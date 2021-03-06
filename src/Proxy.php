<?php

namespace Proxy;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\GenericEvent;

use Proxy\Config;
use Proxy\Event\ProxyEvent;

define('PROXY_VERSION', 2);

class Proxy {

	private $request;
	private $response;
	private $dispatcher;
	
	private $output_buffer = '';
	
	public function __construct(){
		$this->dispatcher = new EventDispatcher();
	}
	
	private function header_callback($ch, $headers){
	
		$parts = explode(":", $headers, 2);
		
		// extract status code
		if(preg_match('/HTTP\/1.\d+ (\d+)/', $headers, $matches)){
		
			$this->response->setStatusCode($matches[1]);
		
		} else if(count($parts) == 2){
			
			$name = strtolower($parts[0]);
			$value = trim($parts[1]);
			
			// this must be a header: value line
			$this->response->headers->set($name, $value, false);
			
		} else {
		
			// this is the end of headers - last line is always empty - notify the dispatcher about this
			$this->dispatcher->dispatch('request.sent', new ProxyEvent(array('request' => $this->request, 'response' => $this->response)));
		}
		
		return strlen($headers);
	}
	
	private function write_callback($ch, $str){
	
		$len = strlen($str);
		
		$this->dispatcher->dispatch('curl.callback.write', new ProxyEvent(array(
			'request' => $this->request,
			'data' => $str
		)));
		
		// do we need to buffer or not?
		$this->output_buffer .= $str;
		
		return $len;
	}
	
	public function getEventDispatcher(){
		return $this->dispatcher;
	}
	
	private function convertRequest(){
	
	}
	
	public function execute(Request $request){
	
		$this->request = $request;
		$this->response = new Response();
		
		$options = array(
			CURLOPT_CONNECTTIMEOUT 	=> 10,
			CURLOPT_TIMEOUT 		=> 0,
			
			// don't return anything - we have other functions for that
			CURLOPT_RETURNTRANSFER	=> false,
			CURLOPT_HEADER			=> false,
			
			// don't bother with ssl
			CURLOPT_SSL_VERIFYPEER	=> false,
			CURLOPT_SSL_VERIFYHOST	=> false,
			
			// we will take care of redirects
			CURLOPT_FOLLOWLOCATION	=> false,
			CURLOPT_AUTOREFERER		=> false
		);
		
		// this is probably a good place to add custom curl options that way other critical options below would overwrite that
		$config_options = Config::get('curl', array());
		
		$options = array_merge_custom($options, $config_options);
		
		$options[CURLOPT_HEADERFUNCTION] = array($this, 'header_callback');
		$options[CURLOPT_WRITEFUNCTION] = array($this, 'write_callback');
		//$options[CURLOPT_READFUNCTION] = array($this, 'read_callback');
		
		// notify listeners that the request is ready to be sent - last chance to make any modifications
		$this->dispatcher->dispatch('request.before_send', new ProxyEvent(array('request' => $this->request)));
		
		$headers = $this->request->headers->all();
		
		$real = array();
		
		foreach($headers as $name => $value){
		
			if(is_array($value)){
				$value = implode("; ", $value);
			}
			
			$real[] = $name.': '.$value;
		}
		
		$options[CURLOPT_HTTPHEADER] = $real;
		
		if($this->request->getMethod() == 'POST'){
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = http_build_query($this->request->request->all());
		}
		
		// why not just use request->getUri? because that would rearrange the query params alphabetically which some sites don't like
		$options[CURLOPT_URL] = $request->getScheme().'://'.$request->getHttpHost().$request->getRequestUri();//$this->request->getUri();
		
		$ch = curl_init();
		curl_setopt_array($ch, $options);
		
		// fetch the status - (at) is here to ignore the errors that come from exceptions within header/body read
		$result = curl_exec($ch);
		
		// there must have been an error if at this point
		if(!$result){
				
			$error = sprintf('(%d) %s', curl_errno($ch), curl_error($ch));
		
			throw new \Exception($error);
		}
		
		// we have output waiting in the buffer?
		$this->response->setContent($this->output_buffer);
		
		$this->dispatcher->dispatch('request.complete', new ProxyEvent(array('request' => $this->request, 'response' => $this->response)));
		
		return $this->response;
	}
}

?>