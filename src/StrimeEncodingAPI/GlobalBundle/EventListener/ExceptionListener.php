<?php

namespace StrimeEncodingAPI\GlobalBundle\EventListener;

use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExceptionListener
{
    
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        // You get the exception object from the received event
        $exception = $event->getException();
        $response = new Response();

        /* $logger = $this->container->get('logger');
        $logger->info('Exception.');
        $logger->info('Message: '.$exception->getMessage());
        $logger->info('Code: '.$exception->getCode()); */

        if ($exception instanceof HttpExceptionInterface) {
            if($exception->getStatusCode() == 403) {

                // Set the response
                $json = array(
                    "application" => $this->container->getParameter('app_name'), 
                    "version" => $this->container->getParameter('app_version'), 
                    "authorization" => "You are not authorized to access this API."
                );

                
                $response->headers->set('Content-Type', 'application/json');
                $response->setStatusCode(401);
                $response->setContent( json_encode($json) );

                // Send the modified response object to the event
                $event->setResponse($response);
            }
        }
    }
}