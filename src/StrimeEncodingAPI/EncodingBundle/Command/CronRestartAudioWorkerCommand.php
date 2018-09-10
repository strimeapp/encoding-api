<?php

namespace StrimeEncodingAPI\EncodingBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Aws\S3\S3Client;
use FFMpeg;

class CronRestartAudioWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('cron:worker:restart-audio')
            ->setDescription('Restart a worker that has been interrupted')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Set the API variables
        $strime_api_url = $this->getContainer()->getParameter('strime_api_url');
        $strime_api_token = $this->getContainer()->getParameter('strime_api_token');

        // Set the headers
        $headers = array(
            'Accept' => 'application/json',
            'X-Auth-Token' => $strime_api_token,
            'Content-type' => 'application/json'
        );

        // Get the list of encoding jobs
        $output->writeln( "[".date("Y-m-d H:i:s")."] Check to see if there is a worker stucked for more than 20 minutes." );

        // Set the endpoint to get the encoding jobs which are stucked
        $endpoint = $strime_api_url."encoding-job/audio/is-worker/stucked";

        // Get the encoding jobs stucked
        $client = new \GuzzleHttp\Client();
        $json_response = $client->request('GET', $endpoint, [
            'headers' => $headers,
            'http_errors' => false,
        ]);
        $curl_status = $json_response->getStatusCode();
        $response = json_decode($json_response->getBody());

        $output->writeln( "[".date("Y-m-d H:i:s")."] cURL status: ".$curl_status );

        // If the request was properly executed
        if($curl_status == 200) {

            $output->writeln( "[".date("Y-m-d H:i:s")."] Restarting the workers." );

            // Start a new job with the first item
            $jobs = $response->{'stucked_jobs'};

            foreach ($jobs as $job_id) {

                // Restart the job
                // Prepare the parameters
                $params = array(
                    'started' => 0,
                    'status' => 0
                );
                $endpoint = $strime_api_url.'encoding-job/audio/'.$job_id.'/edit';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('PUT', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'json' => $params,
                ]);
                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());

                if($curl_status == 200) {
                    $output->writeln( "[".date("Y-m-d H:i:s")."] Job #".$job_id.": restarted" );
                }
                else {
                    $output->writeln( "[".date("Y-m-d H:i:s")."] Job #".$job_id.": ERROR" );
                }
            }

            // write a message in the output
            $output->writeln( "[".date("Y-m-d H:i:s")."] Script stopped." );
        }
        else {
            $output->writeln( $response->{'message'} );
        }
    }
}
