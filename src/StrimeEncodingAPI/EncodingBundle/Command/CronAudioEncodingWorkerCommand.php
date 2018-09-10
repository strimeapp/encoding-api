<?php

namespace StrimeEncodingAPI\EncodingBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\Bundle\DoctrineBundle\Registry;

use Aws\S3\S3Client;
use Strime\Slackify\Webhooks\Webhook;
use FFMpeg;

class CronAudioEncodingWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('cron:worker:encode-audio')
            ->setDescription('Launch a new worker to encode audios if needed')
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
        $output->writeln( "[".date("Y-m-d H:i:s")."] Check to see if there is a job to start." );

        // Set the endpoint to get the audios of a user or a project
        $endpoint = $strime_api_url."encoding-job/audio/new-worker/start";

        // Get the URL of the current server
        switch (gethostname()) {
            case 'encoding':
                $encoding_server = "https://encoding.strime.io/";
                break;
            case 'encoding---bobby':
                $encoding_server = "https://encoding-bobby.strime.io/";
                break;
            case 'encoding---franck':
                $encoding_server = "https://encoding-franck.strime.io/";
                break;
            case 'encoding---jp':
                $encoding_server = "https://encoding-jp.strime.io/";
                break;
            case 'test':
                $encoding_server = "https://encoding-dev.strime.io/";
                break;
            case 'MacBook-Pro-de-Romain.local':
                $encoding_server = "http://localhost:8888/Strime/Encoding/web/app_dev.php/";
                break;
            case 'encoding01':
            case 'str-enc01':
            case 'str---enc01':
                $encoding_server = "https://encoding01.strime.io/";
                break;
            case 'encoding02':
            case 'str-enc02':
            case 'str---enc02':
                $encoding_server = "https://encoding02.strime.io/";
                break;

            default:
                $encoding_server = "https://encoding.strime.io/";
                break;
        }

        // Set the parameters
        $params = array(
            "encoding_server" => $encoding_server
        );

        // Get the audios of this user
        $client = new \GuzzleHttp\Client();
        $json_response = $client->request('POST', $endpoint, [
            'headers' => $headers,
            'http_errors' => false,
            'json' => $params
        ]);

        $curl_status = $json_response->getStatusCode();
        $response = json_decode($json_response->getBody());

        // If the request was properly executed
        if($curl_status == 200) {

            if(($response->{'start_job'} == TRUE) && ($response->{'new_job'} != NULL)) {

                // Set the logger
                $logger = $this->getContainer()->get('logger');


                $output->writeln( "[".date("Y-m-d H:i:s")."] Starting a new job." );

                // Start a new job with the first item
                $job = $response->{'new_job'};


                // Update the start time of the encoding job in the stats
                $endpoint = $strime_api_url.'encoding-job/audio/'.$job->{'encoding_job_id'}.'/update-stats/start-time';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('GET', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false
                ]);

                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());


                // Update the encoding job status
                // Prepare the parameters
                $params = array(
                    'started' => 1
                );
                $endpoint = $strime_api_url.'encoding-job/audio/'.$job->{'encoding_job_id'}.'/edit';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('PUT', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'json' => $params
                ]);

                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());

                // Set the variables
                $encoding_job_id = $job->{'encoding_job_id'};
                $project_id = $job->{'project_id'};
                $user_id = $job->{'user_id'};
                $audio_id = $job->{'audio_id'};
                $audio_filename = $job->{'filename'};
                $upload_path = $job->{'upload_path'};
                $full_audio_path = $job->{'full_audio_path'};

                // Get the audio filename
                $audio_filename_wo_ext_parts = explode('.', $audio_filename);
                $audio_filename_wo_ext = $audio_filename_wo_ext_parts[0].'-converted';

                // Unlink the files if they existed
                if( file_exists($upload_path . '/' . $audio_filename_wo_ext.'.mp3') )
                    unlink($upload_path . '/' . $audio_filename_wo_ext.'.mp3');
                if( file_exists($upload_path . '/' . $audio_filename_wo_ext.'.webm') )
                    unlink($upload_path . '/' . $audio_filename_wo_ext.'.webm');


                // Update the encoding job status
                // Prepare the parameters
                $params = array(
                    'status' => 5
                );
                $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('PUT', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'json' => $params
                ]);

                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());


                // Convert the audio using FFMPEG
                $output->writeln( "[".date("Y-m-d H:i:s")."] Set format for the new audio file." );
                $ffmpeg = FFMpeg\FFMpeg::create([
                    'ffmpeg.binaries'  => $this->getContainer()->getParameter('ffmpeg_ffmpeg_path'), // the path to the FFMpeg binary
                    'ffprobe.binaries' => $this->getContainer()->getParameter('ffmpeg_ffprobe_path'), // the path to the FFProbe binary
                    'timeout'          => $this->getContainer()->getParameter('ffmpeg_timeout'), // the timeout for the underlying process
                    'ffmpeg.threads'   => $this->getContainer()->getParameter('ffmpeg_threads'),   // the number of threads that FFMpeg should use
                ], $logger);

                // Open the audio
                $audio_ffmpeg = $ffmpeg->open( $full_audio_path );

                // Set the formats
                $format_mp3 = new FFMpeg\Format\Audio\Mp3();
                $format_mp3->setAudioCodec("libmp3lame");
                $format_webm = new FFMpeg\Format\Video\WebM();



                // Update the encoding job status
                // Prepare the parameters
                $params = array(
                    'status' => 10
                );
                $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('PUT', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'json' => $params
                ]);

                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());



                // Convert the audio
                $output->writeln( "[".date("Y-m-d H:i:s")."] Convert in MP3." );

                // Set the progress percentage based on the encoding status
                $format_mp3->on('progress', function ($audio_ffmpeg, $format_mp3, $percentage) use ($output, $encoding_job_id, $strime_api_url, $headers) {
                    $output->writeln( "[".date("Y-m-d H:i:s")."] - ".$percentage."%" );

                    // Set the global progress percentage
                    // We already are at 10%
                    // We need to create the ratio
                    $global_progress_percentage = round(10 + ($percentage * 30 / 100), 0);

                    // Update the encoding job status
                    // Prepare the parameters
                    $params = array(
                        'status' => $global_progress_percentage
                    );
                    $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                    // Send the cURL request
                    $client = new \GuzzleHttp\Client();
                    $json_response = $client->request('PUT', $endpoint, [
                        'headers' => $headers,
                        'http_errors' => false,
                        'json' => $params
                    ]);

                    $curl_status = $json_response->getStatusCode();
                    $response = json_decode($json_response->getBody());
                });

                $audio_ffmpeg
                    ->save($format_mp3, $upload_path . '/' . $audio_filename_wo_ext.'.mp3');



                // Convert the audio
                $output->writeln( "[".date("Y-m-d H:i:s")."] Convert in WebM." );

                // Set the progress percentage based on the encoding status
                $format_webm->on('progress', function ($audio_ffmpeg, $format_webm, $percentage) use ($output, $encoding_job_id, $strime_api_url, $headers) {
                    $output->writeln( "[".date("Y-m-d H:i:s")."] - ".$percentage."%" );

                    // Set the global progress percentage
                    // We already are at 10%
                    // We need to create the ratio
                    $global_progress_percentage = round(40 + ($percentage * 30 / 100), 0);

                    // Update the encoding job status
                    // Prepare the parameters
                    $params = array(
                        'status' => $global_progress_percentage
                    );
                    $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                    // Send the cURL request
                    $client = new \GuzzleHttp\Client();
                    $json_response = $client->request('PUT', $endpoint, [
                        'headers' => $headers,
                        'http_errors' => false,
                        'json' => $params
                    ]);

                    $curl_status = $json_response->getStatusCode();
                    $response = json_decode($json_response->getBody());
                });

                $audio_ffmpeg
                    ->save($format_webm, $upload_path . '/' . $audio_filename_wo_ext.'.webm');



                // Update the encoding job status
                // Prepare the parameters
                $params = array(
                    'status' => 70
                );
                $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('PUT', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'json' => $params
                ]);

                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());



                // Instantiate the S3 client using your credential profile
                $output->writeln( "[".date("Y-m-d H:i:s")."] Instantiate S3." );
                $aws = S3Client::factory(array(
                    'credentials' => array(
                        'key'       => $this->getContainer()->getParameter('aws_key'),
                        'secret'    => $this->getContainer()->getParameter('aws_secret')
                    ),
                    'version' => 'latest',
                    'region' => $this->getContainer()->getParameter('aws_region')
                ));

                // Get client instances from the service locator by name
                // $s3Client = $aws->get('s3');

                // Get the buckets list
                $buckets_list = $aws->listBuckets();

                // Generate the bucket folder
                $bucket_folder = $user_id."/";
                if($project_id != NULL)
                    $bucket_folder .= $project_id."/";


                // Send the files to Amazon S3
                foreach ($buckets_list['Buckets'] as $bucket) {

                    if(strcmp($bucket['Name'], $this->getContainer()->getParameter('aws_bucket_audios')) == 0) {

                        // Upload the file to S3
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Upload the original file." );
                        $s3_upload = $aws->putObject(array(
                            'Bucket'     => $bucket['Name'],
                            'Key'        => $bucket_folder.$audio_filename,
                            'SourceFile' => $full_audio_path
                        ));


                        // Update the encoding job status
                        // Prepare the parameters
                        $params = array(
                            'status' => 80
                        );
                        $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                        // Send the cURL request
                        $client = new \GuzzleHttp\Client();
                        $json_response = $client->request('PUT', $endpoint, [
                            'headers' => $headers,
                            'http_errors' => false,
                            'json' => $params
                        ]);

                        $curl_status = $json_response->getStatusCode();
                        $response = json_decode($json_response->getBody());


                        // Upload the mp3 converted file
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Upload the MP3 file." );
                        $s3_upload_mp3 = $aws->putObject(array(
                            'Bucket'     => $bucket['Name'],
                            'Key'        => $bucket_folder.$audio_filename_wo_ext.'.mp3',
                            'SourceFile' => $upload_path.'/'.$audio_filename_wo_ext.'.mp3'
                        ));


                        // Update the encoding job status
                        // Prepare the parameters
                        $params = array(
                            'status' => 90
                        );
                        $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                        // Send the cURL request
                        $client = new \GuzzleHttp\Client();
                        $json_response = $client->request('PUT', $endpoint, [
                            'headers' => $headers,
                            'http_errors' => false,
                            'json' => $params
                        ]);

                        $curl_status = $json_response->getStatusCode();
                        $response = json_decode($json_response->getBody());


                        // Upload the webm converted file
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Upload the WebM file." );
                        $s3_upload_webm = $aws->putObject(array(
                            'Bucket'     => $bucket['Name'],
                            'Key'        => $bucket_folder.$audio_filename_wo_ext.'.webm',
                            'SourceFile' => $upload_path.'/'.$audio_filename_wo_ext.'.webm'
                        ));


                        // Update the encoding job status
                        // Prepare the parameters
                        $params = array(
                            'status' => 100
                        );
                        $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                        // Send the cURL request
                        $client = new \GuzzleHttp\Client();
                        $json_response = $client->request('PUT', $endpoint, [
                            'headers' => $headers,
                            'http_errors' => false,
                            'json' => $params
                        ]);

                        $curl_status = $json_response->getStatusCode();
                        $response = json_decode($json_response->getBody());


                        // Update the end time of the encoding job in the stats
                        $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/update-stats/end-time';

                        // Send the cURL request
                        $client = new \GuzzleHttp\Client();
                        $json_response = $client->request('GET', $endpoint, [
                            'headers' => $headers,
                            'http_errors' => false
                        ]);

                        $curl_status = $json_response->getStatusCode();
                        $response = json_decode($json_response->getBody());
                    }
                }


                // If the upload occured properly
                if(($s3_upload != NULL) && ($s3_upload_mp3 != NULL) && ($s3_upload_webm != NULL)) {

                    // Get the URL of the file on Amazon S3
                    $s3_https_url = $s3_upload['ObjectURL'];
                    $file_name_with_ext = basename( $s3_https_url );
                    $file_name_elts = explode('.', $file_name_with_ext);
                    $file_name_without_ext = $file_name_elts[0];
                    $audio_url = 's3://'.$this->getContainer()->getParameter('aws_bucket').'/'.$file_name_with_ext;

                    // Delete the files locally
                    unlink($full_audio_path);
                    unlink($upload_path.'/'.$audio_filename_wo_ext.'.mp3');
                    unlink($upload_path.'/'.$audio_filename_wo_ext.'.webm');

                    // Delete the folder of the encoding job
                    rmdir($upload_path);


                    // Update the audio with Amazon S3 URLs
                    // Prepare the parameters
                    $params = array(
                        's3_https_url' => $s3_https_url,
                    );
                    $endpoint = $strime_api_url.'audio/'.$audio_id.'/edit';

                    // Send the cURL request
                    $client = new \GuzzleHttp\Client();
                    $json_response = $client->request('PUT', $endpoint, [
                        'headers' => $headers,
                        'http_errors' => false,
                        'json' => $params
                    ]);

                    $curl_status = $json_response->getStatusCode();
                    $response = json_decode($json_response->getBody());


                    // Get the details of the user
                    $endpoint = $strime_api_url."user/".$user_id."/get";
                    $client = new \GuzzleHttp\Client();
                    $json_response = $client->request('GET', $endpoint, [
                        'headers' => $headers,
                        'http_errors' => false,
                    ]);

                    $curl_status = $json_response->getStatusCode();
                    $response = json_decode($json_response->getBody());

                    // If the request was properly executed
                    if($curl_status == 200) {
                        $user = $response->{'results'};

                        // Activate the webhook to send an email
                        $nginx_auth = NULL;
                        $strime_app_url = $this->getContainer()->getParameter('strime_app_url');
                        $strime_app_token = $this->getContainer()->getParameter('strime_app_token');
                        $endpoint = $strime_app_url."app/webhook/encoding/done";

                        // Set the headers
                        $headers_app = array(
                            'Accept' => 'application/json',
                            'X-Auth-Token' => $strime_app_token,
                            'Content-type' => 'application/json'
                        );

                        if(strcmp( $this->getContainer()->get( 'kernel' )->getEnvironment(), "test" ) == 0) {
                            $strime_app_nginx_username = $this->getContainer()->getParameter('strime_app_nginx_username');
                            $strime_app_nginx_pwd = $this->getContainer()->getParameter('strime_app_nginx_pwd');
                            $nginx_auth = [$strime_app_nginx_username, $strime_app_nginx_pwd];
                        }

                        // Set the parameters
                        $params = array(
                            'first_name' => $user->{'first_name'},
                            'last_name' => $user->{'last_name'},
                            'email' => $user->{'email'},
                            'asset_id' => $audio_id,
                            'asset_type' => 'audio',
                            'locale' => $user->{'locale'}
                        );

                        // Set Guzzle
                        $client = new \GuzzleHttp\Client();
                        $json_response = $client->request('POST', $endpoint, [
                            'headers' => $headers_app,
                            'http_errors' => false,
                            'auth' => $nginx_auth,
                            'json' => $params
                        ]);

                        $curl_status = $json_response->getStatusCode();
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Guzzle HTTP Status Code: ".$curl_status );
                        $response = json_decode($json_response->getBody());

                        if($curl_status == 200) {
                            $output->writeln( "[".date("Y-m-d H:i:s")."] Confirmation email has been sent." );
                        }
                        else {
                            $output->writeln( "[".date("Y-m-d H:i:s")."] Confirmation email has NOT been sent. CURL status: ".$curl_status );

                            // Prepare the text of the notification
                            $text_notification = "User: ".$user->{'first_name'}." ".$user->{'last_name'}." (".$user->{'user_id'}.")"."\n";
                            $text_notification .= "User email: ".$user->{'email'}."\n";
                            $text_notification .= "Audio ID: ".$audio_id;

                            // Send a Slack notification
                            $slack_webhook = new Webhook( $this->getContainer()->getParameter('slack_strime_encoding_channel') );
                            $slack_webhook->setAttachments(
                                array(
                                    array(
                                        "fallback" => "Détails de l'encodage",
                                        "text" => $text_notification,
                                        "color" => "danger",
                                        "author" => array(
                                            "author_name" => "Mr Encoding Robot"
                                        ),
                                        "title" => "Détails de l'encodage",
                                        "fields" => array(
                                            "title" => "Détails de l'encodage",
                                            "value" => $text_notification,
                                            "short" => FALSE
                                        ),
                                        "footer_icon" => "https://www.strime.io/bundles/strimeglobal/img/icon-strime.jpg",
                                        "ts" => time()
                                    )
                                )
                            );
                            $slack_webhook->sendMessage(array(
                                "message" => "Erreur lors de l'envoi de l'email de notification de fin d'encodage",
                                "username" => "[".ucfirst( $this->getContainer()->getParameter("kernel.environment") )."] ".$encoding_server,
                                "icon" => ":alembic:"
                            ));
                            $output->writeln( "[".date("Y-m-d H:i:s")."] Slack notification sent." );
                        }
                    }
                    else {

                        // If the audio cannot be found,
                        // Send a message back to the system
                        $output->writeln( "[".date("Y-m-d H:i:s")."] No user was found with this ID. Impossible to activate the webhook to send an email." );

                        // Prepare the text of the notification
                        $text_notification = "User: ".$user->{'first_name'}." ".$user->{'last_name'}." (".$user->{'user_id'}.")"."\n";
                        $text_notification .= "User email: ".$user->{'email'}."\n";
                        $text_notification .= "Audio ID: ".$audio_id;

                        // Send a Slack notification
                        $slack_webhook = new Webhook( $this->getContainer()->getParameter('slack_strime_encoding_channel') );
                        $slack_webhook->setAttachments(
                            array(
                                array(
                                    "fallback" => "Détails de l'encodage",
                                    "text" => $text_notification,
                                    "color" => "danger",
                                    "author" => array(
                                        "author_name" => "Mr Encoding Robot"
                                    ),
                                    "title" => "Détails de l'encodage",
                                    "fields" => array(
                                        "title" => "Détails de l'encodage",
                                        "value" => $text_notification,
                                        "short" => FALSE
                                    ),
                                    "footer_icon" => "https://www.strime.io/bundles/strimeglobal/img/icon-strime.jpg",
                                    "ts" => time()
                                )
                            )
                        );
                        $slack_webhook->sendMessage(array(
                            "message" => "Aucun utilisateur trouvé avec cet ID. Impossible de pinger le webhook d'envoi de l'email de notification",
                            "username" => "[".ucfirst( $this->getContainer()->getParameter("kernel.environment") )."] ".$encoding_server,
                            "icon" => ":alembic:"
                        ));
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Slack notification sent." );
                    }


                    // Set the endpoint to delete the encoding job
                    $endpoint = $strime_api_url."encoding-job/audio/".$encoding_job_id."/delete";

                    // Send the request to delete the audio
                    $client = new \GuzzleHttp\Client();
                    $json_response = $client->request('DELETE', $endpoint, [
                        'headers' => $headers,
                        'http_errors' => false,
                    ]);

                    $curl_status = $json_response->getStatusCode();
                    $output->writeln( "[".date("Y-m-d H:i:s")."] We delete the encoding job data." );
                    $response = json_decode($json_response->getBody());

                    // If the request was properly executed
                    if($curl_status == 204) {

                        $output->writeln( "[".date("Y-m-d H:i:s")."] Woot! Everything worked fine." );

                        // Prepare the text of the notification
                        $text_notification = "User: ".$user->{'first_name'}." ".$user->{'last_name'}." (".$user->{'user_id'}.")"."\n";
                        $text_notification .= "User email: ".$user->{'email'}."\n";
                        $text_notification .= "Audio ID: ".$audio_id;

                        // Send a Slack notification
                        $slack_webhook = new Webhook( $this->getContainer()->getParameter('slack_strime_encoding_channel') );
                        $slack_webhook->setAttachments(
                            array(
                                array(
                                    "fallback" => "Détails de l'encodage",
                                    "text" => $text_notification,
                                    "color" => "#0CAC9A",
                                    "author" => array(
                                        "author_name" => "Mr Encoding Robot"
                                    ),
                                    "title" => "Détails de l'encodage",
                                    "fields" => array(
                                        "title" => "Détails de l'encodage",
                                        "value" => $text_notification,
                                        "short" => FALSE
                                    ),
                                    "footer_icon" => "https://www.strime.io/bundles/strimeglobal/img/icon-strime.jpg",
                                    "ts" => time()
                                )
                            )
                        );
                        $slack_webhook->sendMessage(array(
                            "message" => "Nouvel encodage effectué avec succès",
                            "username" => "[".ucfirst( $this->getContainer()->getParameter("kernel.environment") )."] ".$encoding_server,
                            "icon" => ":alembic:"
                        ));
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Slack notification sent." );

                        // Return something
                        return TRUE;
                    }

                    // If the encoding job was not deleted from the DB
                    else {
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Error when deleting the encoding job in the database." );

                        // Prepare the text of the notification
                        $text_notification = "User: ".$user->{'first_name'}." ".$user->{'last_name'}." (".$user->{'user_id'}.")"."\n";
                        $text_notification .= "User email: ".$user->{'email'}."\n";
                        $text_notification .= "Audio ID: ".$audio_id;

                        // Send a Slack notification
                        $slack_webhook = new Webhook( $this->getContainer()->getParameter('slack_strime_encoding_channel') );
                        $slack_webhook->setAttachments(
                            array(
                                array(
                                    "fallback" => "Détails de l'encodage",
                                    "text" => $text_notification,
                                    "color" => "danger",
                                    "author" => array(
                                        "author_name" => "Mr Encoding Robot"
                                    ),
                                    "title" => "Détails de l'encodage",
                                    "fields" => array(
                                        "title" => "Détails de l'encodage",
                                        "value" => $text_notification,
                                        "short" => FALSE
                                    ),
                                    "footer_icon" => "https://www.strime.io/bundles/strimeglobal/img/icon-strime.jpg",
                                    "ts" => time()
                                )
                            )
                        );
                        $slack_webhook->sendMessage(array(
                            "message" => "Erreur lors de la suppression de l'encodage de la DB",
                            "username" => "[".ucfirst( $this->getContainer()->getParameter("kernel.environment") )."] ".$encoding_server,
                            "icon" => ":alembic:"
                        ));
                        $output->writeln( "[".date("Y-m-d H:i:s")."] Slack notification sent." );
                    }
                }

                // If an error occured during the upload to Amazon S3
                else {

                    // Update the encoding job status
                    // Prepare the parameters
                    $params = array(
                        'error_code' => 520
                    );
                    $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

                    // Send the cURL request
                    $client = new \GuzzleHttp\Client();
                    $json_response = $client->request('PUT', $endpoint, [
                        'headers' => $headers,
                        'http_errors' => false,
                        'json' => $params
                    ]);

                    $curl_status = $json_response->getStatusCode();
                    $output->writeln( "[".date("Y-m-d H:i:s")."] An error occured during the upload." );
                    $response = json_decode($json_response->getBody());

                    // Prepare the text of the notification
                    $text_notification = "User: ".$user->{'first_name'}." ".$user->{'last_name'}." (".$user->{'user_id'}.")"."\n";
                    $text_notification .= "User email: ".$user->{'email'}."\n";
                    $text_notification .= "Audio ID: ".$audio_id;

                    // Send a Slack notification
                    $slack_webhook = new Webhook( $this->getContainer()->getParameter('slack_strime_encoding_channel') );
                    $slack_webhook->setAttachments(
                        array(
                            array(
                                "fallback" => "Détails de l'encodage",
                                "text" => $text_notification,
                                "color" => "danger",
                                "author" => array(
                                    "author_name" => "Mr Encoding Robot"
                                ),
                                "title" => "Détails de l'encodage",
                                "fields" => array(
                                    "title" => "Détails de l'encodage",
                                    "value" => $text_notification,
                                    "short" => FALSE
                                ),
                                "footer_icon" => "https://www.strime.io/bundles/strimeglobal/img/icon-strime.jpg",
                                "ts" => time()
                            )
                        )
                    );
                    $slack_webhook->sendMessage(array(
                        "message" => "Erreur lors de l'envoi des vidéos encodées sur Amazon",
                        "username" => "[".ucfirst( $this->getContainer()->getParameter("kernel.environment") )."] ".$encoding_server,
                        "icon" => ":alembic:"
                    ));
                    $output->writeln( "[".date("Y-m-d H:i:s")."] Slack notification sent." );

                    // Return something
                    return FALSE;
                }
            }

            elseif( $response->{'new_job'} == NULL ) {

                // If there is no job to deal with
                // write a message in the output
                $output->writeln( "[".date("Y-m-d H:i:s")."] No encoding job to deal with." );
                $output->writeln( "[".date("Y-m-d H:i:s")."] Script stopped." );

                // Return something
                return false;
            }

            // If we have already reached the max number of running jobs
            // write a message in the output
            else {
                $output->writeln( "[".date("Y-m-d H:i:s")."] Max number of running jobs reached." );
                $output->writeln( "[".date("Y-m-d H:i:s")."] Script stopped." );
            }
        }
        else {
            if(isset($response->{'message'})) {
                $output->writeln( "[".date("Y-m-d H:i:s")."] ".$response->{'message'} );
            }
            else {
                $output->writeln( "[".date("Y-m-d H:i:s")."] Impossible to fetch encoding jobs. cURL status: ".$curl_status );
            }
        }
    }
}
