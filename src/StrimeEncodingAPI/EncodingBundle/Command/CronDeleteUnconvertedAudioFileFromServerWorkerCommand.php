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

class CronDeleteUnconvertedAudioFileFromServerWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('cron:worker:delete-unconverted-audio-file-from-server')
            ->setDescription('Deletes the audio files that have not been used for more than a certain time')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Set the audio file lifetime
        $audio_file_lifetime = (int)$this->getContainer()->getParameter('video_file_unconverted_lifetime');
        $output->writeln( "[".date("Y-m-d H:i:s")."] Set the audio files lifetime to ".$audio_file_lifetime." seconds." );

        // Set the base path
        $output->writeln( "[".date("Y-m-d H:i:s")."] Set the base path" );
        $base_path = realpath( __DIR__.'/../../../../web/uploads/audio/' );

        // Get the list of files
        $output->writeln( "[".date("Y-m-d H:i:s")."] Get the list of files" );
        $files_action = $this->getContainer()->get('strime_encoding_api.helpers.files_action');
        $files = $files_action->listAllFilesInDirectory($base_path);
        $output->writeln( "[".date("Y-m-d H:i:s")."] ".count($files)." files found" );

        // Foreach file
        if(count($files) > 0) {

            foreach ($files as $file) {

                $output->writeln( "[".date("Y-m-d H:i:s")."] File: ".$file );

                // Get the last time the file has been used
                $last_used = (int)filemtime($file);
                $output->writeln( "[".date("Y-m-d H:i:s")."] - Last used: ".$last_used );

                // Calculate the diffence with the current time
                $difference_with_current_time = time() - $last_used;
                $output->writeln( "[".date("Y-m-d H:i:s")."] - Difference with current time: ".$difference_with_current_time );

                // Check if the file has been used more recently than its lifetime
                if($difference_with_current_time > $audio_file_lifetime) {

                    // Delete the file and its parent folder if needed
                    $file_deleted = $files_action->unlinkFileAndParentDirectory($file);

                    if($file_deleted) {
                        $output->writeln( "[".date("Y-m-d H:i:s")."] - <info>File deleted</info>" );
                    }
                    else {
                        $output->writeln( "[".date("Y-m-d H:i:s")."] - <error>File NOT deleted</error>" );
                    }
                }

            }
        }

        $output->writeln( "[".date("Y-m-d H:i:s")."] End of the script" );
    }
}
