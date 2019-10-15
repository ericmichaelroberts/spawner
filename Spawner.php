<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/*
|	Author: 	Eric Roberts
|	Version:	1.0
|	Updated:	2018-05-17
|
---Description---

The purpose of this object is to encapsulate starting, checking and (if necessary) terminating child-processes such that:

	1)	You don't have to know anything about proc_open, resource handles, stream pipes, CLI syntax or interprocess signaling.
	2)	Your parent process's code doesn't have to issue cURL requests to itself as a workaround for implementing process forking.
	3)	Your child process's code doesn't require any additional code to work as a child process, and to terminate when the parent process exits, if specified.
	3)	You can spend more time coding new functionality, less time reinventing service/cron/task/LRP/daemon control, and no time worrying about runaway threads.

---Usage---

GLOSSARY OF TERMS:

"tethered" - If TRUE, means child process will be terminated if it's parent process terminates.
"immediate" - If TRUE, child process is started immediately.

SIMPLE SYNTAX

-	Create instance of Spawner Object:

		$spawner = new Spawner( 'ControllerName' );

-	Activate instance (actually start child process running)

		$spawner->run();

-	Create instance of Spawner Object that Runs immediately when Created:

		$spawner = new Spawner( 'ControllerName', true );

-	Spawn a child process that continues even after it's parent process terminates:

		$spawner = new Spawner( 'ControllerName' );
		$spawner->tethered = false;

-	Specify additional controller parameters:

		$spawner = new Spawner( 'ControllerName', 'param0', 'param1', 'paramN'... );
		--OR--
		$spawner = new Spawner( 'ControllerName param0 param1 paramN' );

-	Get the PID of the spawned child process:

	$child_pid = $spawner->pid;

-	Get Status (wrapper for proc_get_status()):

	$status = $spawner->status;			(result is associative array [command, pid, running, signaled, stopped, exitcode, termsig, stopsig] )

-	Check if child process is running:

	$is_running = $spawner->running;	(result is boolean)


CUSTOM/ADVANCED SYNTAX:

	$spawner = new Spawner([
		'controller'	=>	'piwik',
		'args'			=>	[ 'consolidator', 42 ],
		'tethered'		=>	true,
		'immediate'		=>	false
	]);

	$spawner->run();

	--KEYS/PROPERTIES--

	controller										Required		(string)
	controller_args | args							Optional		(array or space-delimited string)
	tethered					Default TRUE 		Optional		(boolean)
	background					Default FALSE 		Optional 		(boolean)
	immediate					Default FALSE 		Optional 		(boolean)


STATIC USAGE:

	Spawner::Create( 'piwik', 'watcher', true );
	Spawner::Create( 'piwik watcher', true );
	Spawner::Create([
		'controller'	=>	'piwik',
		'args'			=>	'watcher',
		'immediate'		=>	true,
		'tethered'		=>	true
	]);

*/
class Spawner extends DMSA implements JsonSerializable {

	protected $_pid;
	protected $_pipes;
	protected $_resource;
	protected $_descriptor;
	protected $_command;
	protected $_created_at;

	public $pid;
	public $controller;
	public $controller_args;

	public $tethered = true;
	public $background = false;
	public $immediate = false;

	public function get_status(){
		return is_resource( $this->_resource )
			?	proc_get_status( $this->_resource )
			:	null;
	}

	public function get_running(){
		$status = $this->get_status();
		return !is_null( $status )
			?	$status['running']
			:	false;
	}

	public function __get( $prop ){
		$m = "get_{$prop}";
		return method_exists( $this, $m )
			?	$this->$m()
			:	new Exception( __METHOD__."->{$prop} is not a valid property." );
	}

	public function __construct( $controller ){
		$args = func_get_args();
		$options = self::ParseSpec( $args );

		foreach( $options as $key => $value ){
			$this->$key = $value;
		}

		$ci =& get_instance();

		$params = implode( ' ', $this->controller_args );
		$this->_command = "exec php index.php {$controller} {$params}";
		$this->_descriptor = [['pipe','r'],['pipe','w']];
		$this->_pipes = null;

		if( $this->immediate ){
			$this->run();
		}
	}

	public function __destruct(){
		if( $this->tethered ){
			$this->kill();
		}
	}

	public function __invoke(){
		return $this->run();
	}

	public function run(){

		static $hasRun = false;

		if( !$hasRun ){

			$hasRun = true;

			$ci =& get_instance();

			$this->_created_at = time();

			$command = $this->background
				?	"{$this->_command} &"
				:	$this->_command;

			$this->_resource = proc_open(
				$command,
				$this->_descriptor,
				$this->_pipes,
				null,
				[
					'BG_ENV' => is_null( $ci->config->item('bg_env') )
						?	'local'
						:	$ci->config->item('bg_env'),
					'SPAWNER_PID' => getmypid()
				]
			);

			if(is_resource( $this->_resource )){

				stream_set_blocking($this->_pipes[1], 1);

				$pid = stream_get_contents( $this->_pipes[1] );

				if( is_numeric( $pid ) ){
					$this->pid = (int)$pid;
				}

			}
		}

		return $this;
		//return $this->pid;
	}

	public function kill(){

		$pstatus = proc_get_status( $this->_resource );
		$pid = $pstatus['pid'];

		stripos( php_uname( 's' ), 'win' )>-1
			?	exec( "taskkill /F /T /PID $pid" )
			:	exec( "kill -9 $pid" );

		foreach( $this->_pipes as $pipe ){
			if( is_resource( $pipe ) ){
				fclose( $pipe );
			}
		}

		proc_close( $this->_resource );
	}

	public function jsonSerialize(){
		return [
			'pid'			=>	$this->pid,
			'controller'	=>	$this->controller,
			'params'		=>	$this->controller_args,
			'tethered'		=>	$this->tethered,
			'background'	=>	$this->background
		];
	}

	public static function Create( $controller ){
		$args = func_get_args();
		$options = self::ParseSpec( $args );

		$options->tethered = true;
		$options->background = false;
		$options->immediate = true;

		$klass = __CLASS__;

		return new $klass( $options );
	}

	private static function ParseSpec( $initArgs ){
		$output = [];
		$controller = array_shift( $initArgs );

		if( is_object( $controller ) || is_array( $controller ) ){

			$options = (object)$controller;

			$output['controller'] = property_exists( $options, 'controller' )
				?	$options->controller
				:	null;

			$argsKey = property_exists( $options, 'args' )
				?	'args'
				:	'controller_args';

			$output['controller_args'] = property_exists( $options, $argsKey )
				?	( is_string( $options->$argsKey )
					?	explode( ' ', trim( $options->$argsKey ) )
					:	$options->$argsKey)
				:	[];

			$tethered = property_exists( $options, 'tethered' )
				?	!!$options->tethered
				:	true;

			$output['tethered'] = $tethered;

			if( !$tethered && property_exists( $options, 'background' ) ){
				$output['background'] = !!$options->background;
			}

			if( property_exists( $options, 'immediate' ) ){
				$output['immediate'] = !!$options->immediate;
			}

		}else{
			$output['controller'] = $controller;
			$output['controller_args'] = $initArgs;

			if( sizeof( $initArgs ) && is_bool( $initArgs[ sizeof($initArgs) - 1 ] ) ){
				$output['immediate'] = array_pop( $initArgs );
			}

			$output['controller_args'] = $initArgs;
		}

		return $output;
	}

}
