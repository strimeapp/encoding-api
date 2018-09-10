<?php

namespace StrimeEncodingAPI\GlobalBundle\Auth;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManager;

class HeadersAuthorization {

    protected $em;

	public function __contruct(EntityManager $em) {

        $this->em = $em;
	}

	/**
     * @param object $headers The headers sent to the API
     * @return boolean True, if the request is authorized, FALSE if not.
     */
    public function getToken($headers) 
    {
        // Get the token
        $token = $headers->get("X-Auth-Token");
        
        return $token;
    }
}