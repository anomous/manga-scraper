<?php

namespace RedBlanket\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Symfony\Component\Filesystem\Filesystem;

class Scraper extends Command
{
    protected $client;
    protected $config;
    protected $fs;
    protected $input;
    protected $manga;
    protected $output;
    protected $start_at;
    protected $end_at;
    protected $retry = 0;

    public function configure()
    {
        $this
            ->setName('run')
            ->setDescription('MangaPanda image scrapper')
            ->addArgument(
                'url',
                InputArgument::REQUIRED,
                'A URL to scrape data from'
            )
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Full path where to store the files'
            )
            ->addOption(
                'start',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the first chapter to be fetched',
                1
            )
            ->addOption(
                'end',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the last chapter to be fetched',
                0
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $url  = $input->getArgument('url');
        $path = $input->getArgument('path');
        $path = rtrim($path, '/');

        $this->client = new Client;
        $this->config = include_once(ROOT_PATH . '/config.php');
        $this->fs     = new Filesystem;
        $this->output = $output;
        $this->input  = $input;

        $this->start_at = $this->input->getOption('start') ? $this->input->getOption('start') : 0;
        $this->end_at   = $this->input->getOption('end') ? $this->input->getOption('end') : 999;

        // Get manga name
        $parts       = explode('/', $url);
        $mangaFolder = $parts[count($parts) - 1];
        $mangaName   = ucwords(str_replace('-', ' ', $mangaFolder));
        // Set the base folder path
        $this->manga = $path . '/' . $mangaFolder;

        // Create the folder
        $this->fs->mkdir($this->manga);

        $this->output->writeln('');
        $this->output->writeln('<question> ' . $mangaName . ' </question>');
        $this->output->writeln('');

        try {

            // Get chapters link from main manga page
            $comic = $this->crawler($url);

            $comic->filter('#listing tr td:first-child')->each( function($node) {

                $link = $node->filter('a')->first()->attr('href');

                // get chapter number
                $parts = explode('/', $link);
                $chapterNum = $parts[count($parts) - 1];

                if ($chapterNum >= $this->start_at AND $chapterNum <= $this->end_at) {

                    $this->output->writeln('<comment>Chapter ' . $chapterNum . '</comment>');

                    // Set folder location
                    $location = $this->manga . '/' . str_pad($chapterNum, 3, 0, STR_PAD_LEFT);

                    // Create chapter folder
                    $this->fs->mkdir($location);
                    $chapterLink = 'http://mangapanda.com' . $link;

                    $ch = $this->crawler($chapterLink);
                    $countImg = 1;

                    // Navigate page
                    $ch->filter('#pageMenu')->children()->each(function ($child) use ($countImg, $location, $chapterLink) {

                        $imgURL = 'http://mangapanda.com' . $child->attr('value');

                        if ($imgURL == $chapterLink) {
                            $imgURL .= '/1';
                        }

                        $imx   = $this->crawler($imgURL);
                        $src   = $imx->filter('img#img:first-child')->attr('src');

                        $parts = explode('/', $src);
                        $imgName = $parts[count($parts) - 1];

                        if (! file_exists($location . '/' . $imgName)) {

                            $this->download($src, $location . '/' . $imgName);

                            $this->output->writeln('Image: <info>' . $imgURL . '</info>');

                            if ((int) $this->config['image_sleep'] > 0) {
                                sleep((int) $this->config['image_sleep']);
                            }
                        }
                        else {
                            $this->output->writeln('Image: <info>' . $imgURL . '</info> <error>SKIPPED!</error>');
                        }
                    });

                    if ((int) $this->config['page_sleep'] > 0) {
                        sleep((int) $this->config['page_sleep']);
                        $this->output->writeln('<fg=cyan>Please wait ...</>');
                    }
                } //
            });

        }
        catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
        }
    }

    private function crawler($url)
    {
        if ($this->retry > $this->config['max_retry']) {
            $this->output->writeln('<error>Maximum retry reached! Try again later.');
            die;
        }

        try {
            $body = $this->client->get($url)->getBody()->getContents();

            $crawler = new DomCrawler($body);

            $this->retry = 0;

            return $crawler;
        }
        catch (BadResponseException $e) {
            $this->retry++;
        }
    }

    private function download($url, $location)
    {
        switch ($this->config['downloader']) {
            default:
            case 'normal':
                $this->downloadNormal($url, $location);
                break;

            case 'curl':
                $this->downloadCurl($url, $location);
                break;
        }
    }

    private function downloadNormal($url, $location)
    {
        file_put_contents($location, file_get_contents($url));
    }

    private function downloadCurl($url, $location)
    {
        $ch = curl_init($url);
        $fp = fopen($location, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }
}