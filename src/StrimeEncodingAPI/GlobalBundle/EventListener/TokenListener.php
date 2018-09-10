<?php

namespace StrimeEncodingAPI\GlobalBundle\EventListener;

use StrimeEncodingAPI\GlobalBundle\Controller\TokenAuthenticatedController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use StrimeEncodingAPI\GlobalBundle\Token\TokenGenerator;
use StrimeEncodingAPI\GlobalBundle\Auth\HeadersAuthorization;
use Symfony\Component\HttpFoundation\JsonResponse;


class TokenListener
{
	private $container;

    public function __construct($container)
    {
   		$this->container = $container;
    }

    public function onKernelController(FilterControllerEvent $event)
    {
        $controller = $event->getController();

        /*
         * $controller passed can be either a class or a Closure.
         * This is not usual in Symfony but it may happen.
         * If it is a class, it comes in array format
         */
        if (!is_array($controller)) {
            return;
        }

        if ($controller[0] instanceof TokenAuthenticatedController) {

        	// Get the request
        	$request = $event->getRequest();
            
        	// Get the headers of the request
            $headers = $request->headers;
	        $headersAuth = new HeadersAuthorization;
	        $token = $headersAuth->getToken($headers);

            // Check if we got an answer, if not, send a response
            if(strcmp($token, $this->container->getParameter('app_security_token')) != 0) {
                throw new AccessDeniedHttpException('This action needs a valid token!');
            }
            else {
            	$event->getRequest()->attributes->set('auth_result', TRUE);
            }
        }
        else {

        }
    }


    public function onKernelResponse(FilterResponseEvent $event)
	{
        // Get the result of the authentication
        $auth_result = $event->getRequest()->attributes->get('auth_result');

	    // check to see if onKernelController marked this as a token "auth'ed" request
	    if (!isset($auth_result) || ($auth_result == TRUE)) {
	        return;
	    }

	    $response = $event->getResponse();

	    // Set the response
    	$json = array(
            "application" => $this->container->getParameter('app_name'), 
            "version" => $this->container->getParameter('app_version'), 
            "authorization" => "You are not authorized to access this API."
        );

        $response->headers->set('Content-Type', 'application/json');
        $response->setStatusCode(401);
    	// $response->setContent( new JsonResponse($json, 401, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json')) );
    	$response->setContent( json_encode($json) );
	}
}