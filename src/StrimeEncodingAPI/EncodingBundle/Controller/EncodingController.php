<?php

namespace StrimeEncodingAPI\EncodingBundle\Controller;

use StrimeEncodingAPI\GlobalBundle\Controller\TokenAuthenticatedController;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use FOS\RestBundle\Controller\FOSRestController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use StrimeEncodingAPI\GlobalBundle\Token\TokenGenerator;
use StrimeEncodingAPI\GlobalBundle\Auth\HeadersAuthorization;
use Aws\S3\S3Client;
use FFMpeg;

class EncodingController extends FOSRestController implements TokenAuthenticatedController
{
    /**
     * @Route("/")
     */
    public function indexAction(Request $request)
    {
        return $this->redirect('https://www.strime.io');
    }



    /**
     * @Route("/video/encode")
     */
    public function encodeVideoAction(Request $request)
    {
        // Prepare the response
        $json = array(
            "application" => $this->container->getParameter('app_name'),
            "version" => $this->container->getParameter('app_version'),
            "method" => "/video/encode"
        );

        // Get the data
        $encoding_job_id = $request->request->get('encoding_job_id', NULL);
        $project_id = $request->request->get('project_id', NULL);
        $user_id = $request->request->get('user_id', NULL);
        $video_id = $request->request->get('video_id', NULL);
        $file = $request->files->get('file', NULL);

        // If the type of request used is not the one expected.
        if(!$request->isMethod('POST')) {

            // Set the content of the response
            $json["status"] = "error";
            $json["response_code"] = "405";
            $json["error_message"] = "This is not a POST request.";
            $json["error_source"] = "not_post_request";

            // Create the response object and initialize it
            return new JsonResponse($json, 405, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
            exit;
        }

        // If some data are missing
        elseif($file == NULL) {

            // Set the content of the response
            $json["status"] = "error";
            $json["response_code"] = "400";
            $json["error_message"] = "Error while sending the file.";
            $json["error_source"] = "file_error";
            $json["file"] = $request->files;

            // Create the response object and initialize it
            return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
            exit;
        }

        // We make sure that the upload happened properly.
        elseif((!$file instanceof UploadedFile) || ($file->getError() != 0)) {

            // Set the content of the response
            $json["status"] = "error";
            $json["response_code"] = "400";
            $json["error_message"] = "We have not been able to process the upload. Make sure that you have been following the guidelines with regards to the format and size of your file.";
            $json["error_source"] = "upload_failed";

            // Create the response object and initialize it
            return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
            exit;
        }

        // If everything is fine in this request
        else {

            // Set the API variables
            $strime_api_url = $this->container->getParameter('strime_api_url');
            $strime_api_token = $this->container->getParameter('strime_api_token');

            // Set the headers
            $headers = array(
                'Accept' => 'application/json',
                'X-Auth-Token' => $strime_api_token,
                'Content-type' => 'application/json'
            );

            // Set the base path
            $base_path = realpath( __DIR__.'/../../../../web/uploads/video/' );

            // Create the base folder if it doesn't exist
            if( !file_exists( $base_path.'/' ) )
                mkdir( $base_path.'/', 0755, TRUE );

            // Create a folder for this encoding job
            if( !file_exists( $base_path.'/'.$encoding_job_id.'/' ) ) {
                mkdir( $base_path.'/'.$encoding_job_id.'/', 0755, TRUE );
            }

        	// Get the uploads absolute path
            $upload_path = $base_path . '/' . $encoding_job_id;

            // Get the name of the file and the extension
            $video_filename = basename( $file->getClientOriginalName() );
            $ext = pathinfo( $video_filename, PATHINFO_EXTENSION );

        	// Save the file on the server
        	$file->move( $upload_path, $video_filename);

            // Set a variable with the full video path
            $full_video_path = $upload_path . '/' . $video_filename;


            // Generate the thumbnail

            // Get the video filename
            $video_filename_wo_ext_parts = explode('.', $video_filename);
            $video_filename_wo_ext = $video_filename_wo_ext_parts[0].'-converted';

            // Unlink the JPEG file if it exists
            if( file_exists($upload_path . '/' . $video_filename_wo_ext.'.jpg') )
                unlink($upload_path . '/' . $video_filename_wo_ext.'.jpg');

            // Get the duration of the video and generate a screenshot
            $logger = $this->container->get('logger');
            $screenshot_time = 2;
            $ffprobe = FFMpeg\FFProbe::create([
                'ffmpeg.binaries'  => $this->container->getParameter('ffmpeg_ffmpeg_path'), // the path to the FFMpeg binary
                'ffprobe.binaries' => $this->container->getParameter('ffmpeg_ffprobe_path'), // the path to the FFProbe binary
                'timeout'          => $this->container->getParameter('ffmpeg_timeout'), // the timeout for the underlying process
                'ffmpeg.threads'   => $this->container->getParameter('ffmpeg_threads'),   // the number of threads that FFMpeg should use
            ], $logger);
            $duration = $ffprobe->format( $full_video_path )->get('duration');
            $duration = (float)$duration;

            if(is_float($duration)) {
                $middle = $duration / 2;
                $screenshot_time = (int)floor($middle);
            }

            // Get the image
            $ffmpeg = FFMpeg\FFMpeg::create([
                'ffmpeg.binaries'  => $this->container->getParameter('ffmpeg_ffmpeg_path'), // the path to the FFMpeg binary
                'ffprobe.binaries' => $this->container->getParameter('ffmpeg_ffprobe_path'), // the path to the FFProbe binary
                'timeout'          => $this->container->getParameter('ffmpeg_timeout'), // the timeout for the underlying process
                'ffmpeg.threads'   => $this->container->getParameter('ffmpeg_threads'),   // the number of threads that FFMpeg should use
            ], $logger);
            $video_ffmpeg = $ffmpeg->open( $full_video_path );
            $frame = $video_ffmpeg->frame(FFMpeg\Coordinate\TimeCode::fromSeconds( $screenshot_time ));
            $frame->save( $upload_path . '/' . $video_filename_wo_ext.'.jpg' );



            // Instantiate the S3 client using your credential profile
            $aws = S3Client::factory(array(
                'credentials' => array(
                    'key'       => $this->container->getParameter('aws_key'),
                    'secret'    => $this->container->getParameter('aws_secret')
                ),
                'version' => 'latest',
                'region' => $this->container->getParameter('aws_region')
            ));

            // Get the buckets list
            $buckets_list = $aws->listBuckets();

            // Generate the bucket folder
            $bucket_folder = $user_id."/";
            if($project_id != NULL)
                $bucket_folder .= $project_id."/";


            // Send the files to Amazon S3
            foreach ($buckets_list['Buckets'] as $bucket) {

                if(strcmp($bucket['Name'], $this->container->getParameter('aws_bucket')) == 0) {

                    // Upload the jpg screenshot
                    $s3_upload_screenshot = $aws->putObject(array(
                        'Bucket'     => $bucket['Name'],
                        'Key'        => $bucket_folder.$video_filename_wo_ext.'.jpg',
                        'SourceFile' => $upload_path.'/'.$video_filename_wo_ext.'.jpg'
                    ));

                }
            }

            // If the file has been uploaded to Amazon
            if($s3_upload_screenshot != NULL) {

                // Log the fact that the file has been properly uploaded
                $logger->info( "[".date("Y-m-d H:i:s")."] Amazon: File uploaded" );

                // Get the URL of the file on Amazon S3
                $s3_https_url_screenshot = $s3_upload_screenshot['ObjectURL'];

                // Log the fact that the file has been properly uploaded
                $logger->info( "[".date("Y-m-d H:i:s")."] Amazon URL: " . $s3_https_url_screenshot );

                // Delete the files locally
                unlink($upload_path.'/'.$video_filename_wo_ext.'.jpg');


                // Update the video with Amazon S3 URLs
                // Prepare the parameters
                $params = array(
                    's3_https_url_screenshot' => $s3_https_url_screenshot
                );
                $endpoint = $strime_api_url.'video/'.$video_id.'/edit';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('PUT', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'json' => $params,
                ]);
                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());
            }

            // If the file has not been uploaded to Amazon
            else {

                // Log the fact that the file has not been uploaded
                $logger->info( "[".date("Y-m-d H:i:s")."] Amazon: File NOT uploaded" );
            }


            // Update the encoding job details
            // Prepare the parameters
            $params = array(
                'filename' => $video_filename,
                'extension' => $ext,
                'upload_path' => $upload_path,
                'full_video_path' => $full_video_path,
            );
            $endpoint = $strime_api_url.'encoding-job/video/'.$encoding_job_id.'/edit';

            // Send the cURL request
            $client = new \GuzzleHttp\Client();
            $json_response = $client->request('PUT', $endpoint, [
                'headers' => $headers,
                'http_errors' => false,
                'json' => $params,
            ]);
            $curl_status = $json_response->getStatusCode();
            $response = json_decode($json_response->getBody());

            // Send a response to the API before processing the file
            $json["response_code"] = 201;
            $json["video_filesize"] = filesize($full_video_path);
            return new JsonResponse($json, 201, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
        }
    }



    /**
     * @Route("/audio/encode")
     */
    public function encodeAudioAction(Request $request)
    {
        // Prepare the response
        $json = array(
            "application" => $this->container->getParameter('app_name'),
            "version" => $this->container->getParameter('app_version'),
            "method" => "/audio/encode"
        );

        // Get the data
        $encoding_job_id = $request->request->get('encoding_job_id', NULL);
        $project_id = $request->request->get('project_id', NULL);
        $user_id = $request->request->get('user_id', NULL);
        $audio_id = $request->request->get('audio_id', NULL);
        $file = $request->files->get('file', NULL);

        // If the type of request used is not the one expected.
        if(!$request->isMethod('POST')) {

            // Set the content of the response
            $json["status"] = "error";
            $json["response_code"] = "405";
            $json["error_message"] = "This is not a POST request.";
            $json["error_source"] = "not_post_request";

            // Create the response object and initialize it
            return new JsonResponse($json, 405, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
            exit;
        }

        // If some data are missing
        elseif($file == NULL) {

            // Set the content of the response
            $json["status"] = "error";
            $json["response_code"] = "400";
            $json["error_message"] = "Error while sending the file.";
            $json["error_source"] = "file_error";
            $json["file"] = $request->files;

            // Create the response object and initialize it
            return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
            exit;
        }

        // We make sure that the upload happened properly.
        elseif((!$file instanceof UploadedFile) || ($file->getError() != 0)) {

            // Set the content of the response
            $json["status"] = "error";
            $json["response_code"] = "400";
            $json["error_message"] = "We have not been able to process the upload. Make sure that you have been following the guidelines with regards to the format and size of your file.";
            $json["error_source"] = "upload_failed";

            // Create the response object and initialize it
            return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
            exit;
        }

        // If everything is fine in this request
        else {

            // Set the API variables
            $strime_api_url = $this->container->getParameter('strime_api_url');
            $strime_api_token = $this->container->getParameter('strime_api_token');

            // Set the headers
            $headers = array(
                'Accept' => 'application/json',
                'X-Auth-Token' => $strime_api_token,
                'Content-type' => 'application/json'
            );

            // Set the base path
            $base_path = realpath( __DIR__.'/../../../../web/uploads/audio/' );

            // Create the base folder if it doesn't exist
            if( !file_exists( $base_path.'/' ) )
                mkdir( $base_path.'/', 0755, TRUE );

            // Create a folder for this encoding job
            if( !file_exists( $base_path.'/'.$encoding_job_id.'/' ) ) {
                mkdir( $base_path.'/'.$encoding_job_id.'/', 0755, TRUE );
            }

        	// Get the uploads absolute path
            $upload_path = $base_path . '/' . $encoding_job_id;

            // Get the name of the file and the extension
            $audio_filename = basename( $file->getClientOriginalName() );
            $ext = pathinfo( $audio_filename, PATHINFO_EXTENSION );

        	// Save the file on the server
        	$file->move( $upload_path, $audio_filename);

            // Set a variable with the full video path
            $full_audio_path = $upload_path . '/' . $audio_filename;


            // Generate the thumbnail
            // Get the audio filename
            $audio_filename_wo_ext_parts = explode('.', $audio_filename);
            $audio_filename_wo_ext = $audio_filename_wo_ext_parts[0].'-converted';

            // Get the duration of the video and generate a screenshot
            $logger = $this->container->get('logger');
            $ffprobe = FFMpeg\FFProbe::create([
                'ffmpeg.binaries'  => $this->container->getParameter('ffmpeg_ffmpeg_path'), // the path to the FFMpeg binary
                'ffprobe.binaries' => $this->container->getParameter('ffmpeg_ffprobe_path'), // the path to the FFProbe binary
                'timeout'          => $this->container->getParameter('ffmpeg_timeout'), // the timeout for the underlying process
                'ffmpeg.threads'   => $this->container->getParameter('ffmpeg_threads'),   // the number of threads that FFMpeg should use
            ], $logger);
            $duration = $ffprobe->format( $full_audio_path )->get('duration');
            $duration = (float)$duration;

            // Generate the waveform
            $ffmpeg = FFMpeg\FFMpeg::create([
                'ffmpeg.binaries'  => $this->container->getParameter('ffmpeg_ffmpeg_path'), // the path to the FFMpeg binary
                'ffprobe.binaries' => $this->container->getParameter('ffmpeg_ffprobe_path'), // the path to the FFProbe binary
                'timeout'          => $this->container->getParameter('ffmpeg_timeout'), // the timeout for the underlying process
                'ffmpeg.threads'   => $this->container->getParameter('ffmpeg_threads'),   // the number of threads that FFMpeg should use
            ], $logger);
            $audio_ffmpeg = $ffmpeg->open( $full_audio_path );
            $waveform = $audio_ffmpeg->waveform();
            $waveform->save( $upload_path.'/'.$audio_filename_wo_ext_parts[0].'.png' );



            // Save the path of the files
            $waveform_file = $upload_path.'/'.$audio_filename_wo_ext_parts[0].'.png';
            $waveform_file_player = $upload_path.'/'.$audio_filename_wo_ext_parts[0].'-player.png';

            // Copy the original waveform
            copy($waveform_file, $waveform_file_player);

            // Change the color of the waveform
            $waveform_stream = imagecreatefrompng($waveform_file);
            $waveform_stream_player = imagecreatefrompng($waveform_file_player);

            for ($x=imagesx($waveform_stream); $x--; ) {
                for ($y=imagesy($waveform_stream); $y--; ) {

                    // Get the color of the pixel
                    $rgb = imagecolorat($waveform_stream, $x, $y);
                    $colors = imagecolorsforindex($waveform_stream, $rgb);

                    // Save the old colors
                    $old_red = $colors["red"];
                    $old_green = $colors["green"];
                    $old_blue = $colors["blue"];
                    $old_alpha = $colors["alpha"];

                    // Set the alpha to use
                    if($y > imagesy($waveform_stream) / 2) {
                        $new_alpha = $this->container->getParameter('ffmpeg_waveform_strime_alpha');
                    }
                    else {
                        $new_alpha = 0.3;
                    }

                    // If the pixel is filled with the color generated by FFMPEG, replace it
                    if ($colors["red"] == $this->container->getParameter('ffmpeg_waveform_default_r')
                        && $colors["green"] == $this->container->getParameter('ffmpeg_waveform_default_g')
                        && $colors["blue"] == $this->container->getParameter('ffmpeg_waveform_default_b')) {
                        // here we use the new color, but the original alpha channel
                        $strime_color = imagecolorallocatealpha(
                            $waveform_stream,
                            $this->container->getParameter('ffmpeg_waveform_strime_r'),
                            $this->container->getParameter('ffmpeg_waveform_strime_g'),
                            $this->container->getParameter('ffmpeg_waveform_strime_b'),
                            $new_alpha
                        );
                        imagesetpixel($waveform_stream, $x, $y, $strime_color);
                    }
                    elseif( ($colors["red"] < $this->container->getParameter('ffmpeg_waveform_default_r') + 100)
                        && ($colors["red"] > $this->container->getParameter('ffmpeg_waveform_default_r') - 100)
                        && ($colors["green"] < $this->container->getParameter('ffmpeg_waveform_default_g') + 100)
                        && ($colors["green"] > $this->container->getParameter('ffmpeg_waveform_default_g') - 100)
                        && ($colors["blue"] < $this->container->getParameter('ffmpeg_waveform_default_b') + 100)
                        && ($colors["blue"] > $this->container->getParameter('ffmpeg_waveform_default_b') - 100)) {
                        // here we use the new color, but the original alpha channel
                        $strime_color = imagecolorallocatealpha(
                            $waveform_stream,
                            $this->container->getParameter('ffmpeg_waveform_strime_r'),
                            $this->container->getParameter('ffmpeg_waveform_strime_g'),
                            $this->container->getParameter('ffmpeg_waveform_strime_b'),
                            $new_alpha
                        );
                        imagesetpixel($waveform_stream, $x, $y, $strime_color);
                    }
                    else {
                        // Save a transparent pixel
                        $transparent_color = imagecolorallocatealpha($waveform_stream, $old_red, $old_green, $old_blue, $old_alpha);
                        imagesetpixel($waveform_stream, $x, $y, $transparent_color);
                    }
                }
            }


            for ($x=imagesx($waveform_stream_player); $x--; ) {
                for ($y=imagesy($waveform_stream_player); $y--; ) {

                    // Get the color of the pixel
                    $rgb = imagecolorat($waveform_stream_player, $x, $y);
                    $colors = imagecolorsforindex($waveform_stream_player, $rgb);

                    // Save the old colors
                    $old_red = $colors["red"];
                    $old_green = $colors["green"];
                    $old_blue = $colors["blue"];
                    $old_alpha = $colors["alpha"];

                    // Set the alpha to use
                    if($y > imagesy($waveform_stream_player) / 2) {
                        $new_alpha = $this->container->getParameter('ffmpeg_waveform_strime_alpha');
                    }
                    else {
                        $new_alpha = 0.3;
                    }

                    // If the pixel is filled with the color generated by FFMPEG, replace it
                    if ($colors["red"] == $this->container->getParameter('ffmpeg_waveform_default_r')
                        && $colors["green"] == $this->container->getParameter('ffmpeg_waveform_default_g')
                        && $colors["blue"] == $this->container->getParameter('ffmpeg_waveform_default_b')) {
                        // here we use the new color, but the original alpha channel
                        $strime_color = imagecolorallocatealpha(
                            $waveform_stream_player,
                            $this->container->getParameter('ffmpeg_waveform_strime_grey_r'),
                            $this->container->getParameter('ffmpeg_waveform_strime_grey_g'),
                            $this->container->getParameter('ffmpeg_waveform_strime_grey_b'),
                            $new_alpha
                        );
                        imagesetpixel($waveform_stream_player, $x, $y, $strime_color);
                    }
                    elseif( ($colors["red"] < $this->container->getParameter('ffmpeg_waveform_default_r') + 100)
                        && ($colors["red"] > $this->container->getParameter('ffmpeg_waveform_default_r') - 100)
                        && ($colors["green"] < $this->container->getParameter('ffmpeg_waveform_default_g') + 100)
                        && ($colors["green"] > $this->container->getParameter('ffmpeg_waveform_default_g') - 100)
                        && ($colors["blue"] < $this->container->getParameter('ffmpeg_waveform_default_b') + 100)
                        && ($colors["blue"] > $this->container->getParameter('ffmpeg_waveform_default_b') - 100)) {
                        // here we use the new color, but the original alpha channel
                        $strime_color = imagecolorallocatealpha(
                            $waveform_stream_player,
                            $this->container->getParameter('ffmpeg_waveform_strime_grey_r'),
                            $this->container->getParameter('ffmpeg_waveform_strime_grey_g'),
                            $this->container->getParameter('ffmpeg_waveform_strime_grey_b'),
                            $new_alpha
                        );
                        imagesetpixel($waveform_stream_player, $x, $y, $strime_color);
                    }
                    else {
                        // Save a transparent pixel
                        $transparent_color = imagecolorallocatealpha($waveform_stream_player, $old_red, $old_green, $old_blue, $old_alpha);
                        imagesetpixel($waveform_stream_player, $x, $y, $transparent_color);
                    }
                }
            }

            // Restore Alpha
            imagesavealpha($waveform_stream, TRUE);
            imagepng($waveform_stream, $waveform_file, 3);
            imagedestroy($waveform_stream);

            imagesavealpha($waveform_stream_player, TRUE);
            imagepng($waveform_stream_player, $waveform_file_player, 3);
            imagedestroy($waveform_stream_player);



            // Instantiate the S3 client using your credential profile
            $aws = S3Client::factory(array(
                'credentials' => array(
                    'key'       => $this->container->getParameter('aws_key'),
                    'secret'    => $this->container->getParameter('aws_secret')
                ),
                'version' => 'latest',
                'region' => $this->container->getParameter('aws_region')
            ));

            // Get the buckets list
            $buckets_list = $aws->listBuckets();

            // Generate the bucket folder
            $bucket_folder = $user_id."/";
            if($project_id != NULL)
                $bucket_folder .= $project_id."/";


            // Send the files to Amazon S3
            foreach ($buckets_list['Buckets'] as $bucket) {

                if(strcmp($bucket['Name'], $this->container->getParameter('aws_bucket_audios')) == 0) {

                    // Upload the waveform
                    $s3_upload_waveform = $aws->putObject(array(
                        'Bucket'     => $bucket['Name'],
                        'Key'        => $bucket_folder.$audio_filename_wo_ext_parts[0].'.png',
                        'SourceFile' => $waveform_file
                    ));
                    $s3_upload_waveform_player = $aws->putObject(array(
                        'Bucket'     => $bucket['Name'],
                        'Key'        => $bucket_folder.$audio_filename_wo_ext_parts[0].'-player.png',
                        'SourceFile' => $waveform_file_player
                    ));

                }
            }

            // If the file has been uploaded to Amazon
            if(($s3_upload_waveform != NULL) && ($s3_upload_waveform_player != NULL)) {

                // Log the fact that the file has been properly uploaded
                $logger->info( "[".date("Y-m-d H:i:s")."] Amazon: File uploaded" );

                // Get the URL of the file on Amazon S3
                $s3_https_url_waveform = $s3_upload_waveform['ObjectURL'];

                // Log the fact that the file has been properly uploaded
                $logger->info( "[".date("Y-m-d H:i:s")."] Amazon URL: " . $s3_https_url_waveform );

                // Delete the files locally
                unlink($waveform_file);
                unlink($waveform_file_player);


                // Update the audio with Amazon S3 URLs
                // Prepare the parameters
                $params = array(
                    's3_https_url_thumbnail' => $s3_https_url_waveform
                );
                $endpoint = $strime_api_url.'audio/'.$audio_id.'/edit';

                // Send the cURL request
                $client = new \GuzzleHttp\Client();
                $json_response = $client->request('PUT', $endpoint, [
                    'headers' => $headers,
                    'http_errors' => false,
                    'json' => $params,
                ]);
                $curl_status = $json_response->getStatusCode();
                $response = json_decode($json_response->getBody());
            }

            // If the file has not been uploaded to Amazon
            else {

                // Log the fact that the file has not been uploaded
                $logger->info( "[".date("Y-m-d H:i:s")."] Amazon: File NOT uploaded" );
            }


            // Update the encoding job details
            // Prepare the parameters
            $params = array(
                'filename' => $audio_filename,
                'extension' => $ext,
                'upload_path' => $upload_path,
                'full_audio_path' => $full_audio_path,
            );
            $endpoint = $strime_api_url.'encoding-job/audio/'.$encoding_job_id.'/edit';

            // Send the cURL request
            $client = new \GuzzleHttp\Client();
            $json_response = $client->request('PUT', $endpoint, [
                'headers' => $headers,
                'http_errors' => false,
                'json' => $params,
            ]);
            $curl_status = $json_response->getStatusCode();
            $response = json_decode($json_response->getBody());

            // Send a response to the API before processing the file
            $json["response_code"] = 201;
            $json["audio_filesize"] = filesize($full_audio_path);
            return new JsonResponse($json, 201, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
        }
    }



    /**
     * @Route("/comment/generate-thumbnail")
     */
    public function commentGenerateThumbnailAction(Request $request)
    {
        // Prepare the response
        $json = array(
            "application" => $this->container->getParameter('app_name'),
            "version" => $this->container->getParameter('app_version'),
            "method" => "/comment/generate-thumbnail"
        );

        // Set the logger
        $logger = $this->get('logger');

        // Get the data
        $timecode = $request->request->get('timecode', NULL);
        $video_id = $request->request->get('video_id', NULL);
        $comment_id = $request->request->get('comment_id', NULL);

        // If the type of request used is not the one expected.
        if(!$request->isMethod('POST')) {

            // Set the content of the response
            $json["status"] = "error";
            $json["response_code"] = "405";
            $json["error_message"] = "This is not a POST request.";
            $json["error_source"] = "not_post_request";

            // Create the response object and initialize it
            return new JsonResponse($json, 405, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
            exit;
        }

        // If everything is fine in this request
        else {

            // Set the API variables
            $strime_api_url = $this->container->getParameter('strime_api_url');
            $strime_api_token = $this->container->getParameter('strime_api_token');

            // Set the headers
            $headers = array(
                'Accept' => 'application/json',
                'X-Auth-Token' => $strime_api_token,
                'Content-type' => 'application/json'
            );

            // Set the base path
            $base_path = realpath( __DIR__.'/../../../../web/thumbnails/' );

            // Create a folder for this video
            if( !file_exists( $base_path.'/' ) )
                mkdir( $base_path.'/', 0755, TRUE );

            // Create a folder for this video
            if( !file_exists( $base_path.'/'.$video_id.'/' ) )
                mkdir( $base_path.'/'.$video_id.'/', 0755, TRUE );

        	// Get the uploads absolute path
            $upload_path = $base_path . '/' . $video_id;

            // Get the details of the video
            $endpoint = $strime_api_url.'video/'.$video_id.'/get';

            // Send the cURL request
            $client = new \GuzzleHttp\Client();
            $json_response = $client->request('GET', $endpoint, [
                'headers' => $headers,
                'http_errors' => false
            ]);
            $curl_status = $json_response->getStatusCode();
            $response = json_decode($json_response->getBody());

            if($curl_status != 200) {
                $logger->error("SCREENSHOT GENERATION: video_not_found (".$video_id.")");
                $json["response_code"] = 400;
                $json["error_source"] = "video_not_found";
                return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                die;
            }
            else {
                $video = $response->{'results'};
            }

            // Set the path to the local copy
            $local_copy = $upload_path . '/' . basename( $video->{'s3_url'} );

            // Get the length of the extension
            $ext = pathinfo( basename( $video->{'s3_url'} ), PATHINFO_EXTENSION );
            $ext_length = strlen($ext) + 1;

            // Set the file to copy
            $file_to_copy = substr( $video->{'s3_url'}, 0, -$ext_length ) . "-converted.mp4";
            $file_to_copy_original = $video->{'s3_url'};
            $flag_copy = FALSE;

            // Copy the file locally
            if(!file_exists($local_copy)) {
                try {
                    $flag_copy = @copy($file_to_copy, $local_copy);
                }
                catch (Exception $e) {
                    // Do something with the exception
                }

                if ( !$flag_copy ) {
                    try {
                        $flag_copy = @copy($file_to_copy_original, $local_copy);
                    }
                    catch (Exception $e) {
                        $logger->error("SCREENSHOT GENERATION: copy_failed (".$video_id.")");
                        $json["response_code"] = 400;
                        $json["error_source"] = "copy_failed";
                        return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                        die;
                    }

                    if(!$flag_copy) {
                        $logger->error("SCREENSHOT GENERATION: copy_failed (".$video_id.")");
                        $json["response_code"] = 400;
                        $json["error_source"] = "copy_failed";
                        return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                        die;
                    }
                }

                if($flag_copy == TRUE) {

                    // We set the rights of the file
                    chmod($local_copy, 0775);
                }
            }
            else {
                // We update the timestamp of the file to avoid it to be deleted
                touch($local_copy);
            }

            // Unlink the JPEG file if it exists
            if( file_exists($upload_path . '/comment-' . $comment_id.'.jpg') )
                unlink($upload_path . '/comment-' . $comment_id.'.jpg');

            if(!is_dir($local_copy) && file_exists($local_copy)) {

                // Generate the thumbnail
                $logger = $this->container->get('logger');
                $ffprobe = FFMpeg\FFProbe::create([
                    'ffmpeg.binaries'  => $this->container->getParameter('ffmpeg_ffmpeg_path'), // the path to the FFMpeg binary
                    'ffprobe.binaries' => $this->container->getParameter('ffmpeg_ffprobe_path'), // the path to the FFProbe binary
                    'timeout'          => $this->container->getParameter('ffmpeg_timeout'), // the timeout for the underlying process
                    'ffmpeg.threads'   => $this->container->getParameter('ffmpeg_threads'),   // the number of threads that FFMpeg should use
                ], $logger);

                $ffmpeg = FFMpeg\FFMpeg::create([
                    'ffmpeg.binaries'  => $this->container->getParameter('ffmpeg_ffmpeg_path'), // the path to the FFMpeg binary
                    'ffprobe.binaries' => $this->container->getParameter('ffmpeg_ffprobe_path'), // the path to the FFProbe binary
                    'timeout'          => $this->container->getParameter('ffmpeg_timeout'), // the timeout for the underlying process
                    'ffmpeg.threads'   => $this->container->getParameter('ffmpeg_threads'),   // the number of threads that FFMpeg should use
                ], $logger);

                $duration = $ffprobe->format( $local_copy )->get('duration');
                $duration = (float)$duration;

                $screenshot_time = (int)floor($timecode);
                $video_ffmpeg = $ffmpeg->open( $local_copy );
                $frame = $video_ffmpeg->frame(FFMpeg\Coordinate\TimeCode::fromSeconds( $screenshot_time ));
                $frame->save( $upload_path . '/comment-' . $comment_id . '.jpg' );


                // If the file has been properly generated
                if(file_exists($upload_path . '/comment-' . $comment_id . '.jpg')) {

                    // Instantiate the S3 client using your credential profile
                    $aws = S3Client::factory(array(
                        'credentials' => array(
                            'key'       => $this->container->getParameter('aws_key'),
                            'secret'    => $this->container->getParameter('aws_secret')
                        ),
                        'version' => 'latest',
                        'region' => $this->container->getParameter('aws_region')
                    ));

                    // Get the buckets list
                    $buckets_list = $aws->listBuckets();

                    // Generate the bucket folder
                    $bucket_folder = $video->{'user'}->{'user_id'}."/";
                    if(($video->{'project'} != NULL) && ($video->{'project'}->{'project_id'} != NULL))
                        $bucket_folder .= $video->{'project'}->{'project_id'}."/";


                    // Send the files to Amazon S3
                    foreach ($buckets_list['Buckets'] as $bucket) {

                        if(strcmp($bucket['Name'], $this->container->getParameter('aws_bucket_comments')) == 0) {

                            // Upload the jpg screenshot
                            $s3_upload_screenshot = $aws->putObject(array(
                                'Bucket'     => $bucket['Name'],
                                'Key'        => $bucket_folder.'comment-'.$comment_id.'.jpg',
                                'SourceFile' => $upload_path.'/comment-'.$comment_id.'.jpg'
                            ));

                        }
                    }

                    // If the upload to S3 occured properly
                    if($s3_upload_screenshot != NULL) {

                        // Get the URL of the file on Amazon S3
                        $s3_https_url_screenshot = $s3_upload_screenshot['ObjectURL'];

                        // Delete the files locally
                        unlink($upload_path.'/comment-'.$comment_id.'.jpg');


                        // Update the comment with Amazon S3 URLs
                        // Prepare the parameters
                        $params = array(
                            's3_url' => $s3_https_url_screenshot
                        );
                        $endpoint = $strime_api_url.'comment/'.$comment_id.'/edit';

                        // Send the cURL request
                        $client = new \GuzzleHttp\Client();
                        $json_response = $client->request('PUT', $endpoint, [
                            'headers' => $headers,
                            'http_errors' => false,
                            'json' => $params,
                        ]);
                        $curl_status = $json_response->getStatusCode();
                        $response = json_decode($json_response->getBody());

                        if($curl_status != 200) {
                            $logger->error("SCREENSHOT GENERATION: comment_edition_failed (".$video_id.")");
                            $json["response_code"] = 400;
                            $json["error_source"] = "comment_edition_failed";
                            return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                            die;
                        }

                        $logger->error("SCREENSHOT GENERATION: success!");
                    }

                    // If the upload to S3 failed
                    else {
                        $logger->error("SCREENSHOT GENERATION: upload_failed (".$video_id.")");
                        $json["response_code"] = 400;
                        $json["error_source"] = "upload_failed";
                        return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                        die;
                    }

                    // Send a response to the API before processing the file
                    $json["response_code"] = 200;
                    return new JsonResponse($json, 200, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                }

                // If the screenshot has not been properly generated
                else {
                    $logger->error("SCREENSHOT GENERATION: screenshot_not_generated (".$video_id.")");
                    $json["response_code"] = 400;
                    $json["error_source"] = "screenshot_not_generated";
                    return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                    die;
                }
            }
            else {

                // If the local copy doesn't exist
                $logger->error("SCREENSHOT GENERATION: File not copied -yet?- (".$video_id.")");
                $json["response_code"] = 400;
                $json["error_source"] = "file_not_copied";
                return new JsonResponse($json, 400, array('Access-Control-Allow-Origin' => TRUE, 'Content-Type' => 'application/json'));
                die;
            }
        }
    }
}
