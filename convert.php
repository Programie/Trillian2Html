#! /usr/bin/env php
<?php
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

require_once __DIR__ . "/vendor/autoload.php";

if (!isset($argv[3])) {
    fwrite(STDERR, "Usage: " . $argv[0] . " <own username> <path to Trillian logs dir> <output dir>\n");
    exit(1);
}

$ownName = $argv[1];
$sourceDir = $argv[2];
$outputDir = $argv[3];

$loader = new Twig_Loader_Filesystem(__DIR__);
$twig = new Twig_Environment($loader);

$filesystem = new Filesystem;

$finder = new Finder;

$finder->in($sourceDir);
$finder->name("*.xml");
$finder->files();

$perContactFiles = array();

foreach ($finder as $file) {
    if (!preg_match("/^(.*)-(.*).xml$/", $file->getFilename(), $matches)) {
        fwrite(STDERR, sprintf("Can't parse contact name from filename: %s\n", $file->getFilename()));
        continue;
    }

    $protocol = $matches[1];
    $contactName = $matches[2];

    if (!isset($perContactFiles[$contactName])) {
        $perContactFiles[$contactName] = array();
    }

    $perContactFiles[$contactName][] = $file;
}

foreach ($perContactFiles as $contactName => $files) {
    $previousLog = null;
    $nextLog = null;

    /**
     * @var $file SplFileInfo
     */
    foreach ($files as $fileIndex => $file) {
        // Trillian does not include a root element in the XML files
        $xml = "<root>" . $file->getContents() . "</root>";

        $dom = new DOMDocument;

        $dom->loadXML($xml);

        $messages = array();

        //<message time="1311612005103" type="outgoing_privateMessage" text="some%20message"/>
        /**
         * @var $message DOMElement
         */
        foreach ($dom->getElementsByTagName("message") as $message) {
            $timestamp = $message->getAttribute("time") / 1000;// Timestamp is in milliseconds
            $type = $message->getAttribute("type");
            $text = rawurldecode($message->getAttribute("text"));

            $messageId = sha1(sprintf("%d/%s/%s", $timestamp, $type, $text));

            $date = new DateTime;

            $date->setTimestamp($timestamp);

            switch ($type) {
                case "incoming_privateMessage":
                    $user = $contactName;
                    break;
                case "outgoing_privateMessage":
                    $user = $ownName;
                    break;
                default:
                    fwrite(STDERR, sprintf("Unsupported message type: %s\n", $type));
                    continue 2;
            }

            $text = str_replace("\n", "<br/>\n", $text);

            // Inline images
            $text = preg_replace("/(http|https):\/\/(ft|media).trillian.im\/([^ ]+)/", '<a href="${1}://${2}.trillian.im/${3}" target="_blank"><img src="${1}://${2}.trillian.im/${3}" width="200"/></a>', $text);

            if (isset($messages[$messageId])) {
                fwrite(STDERR, sprintf("Skipping duplicate message: %s - %s\n", $date->format("r"), $text));
                continue;
            }

            $messages[$messageId] = array
            (
                "date" => $date,
                "user" => $user,
                "type" => $type,
                "text" => $text
            );
        }

        if (isset($files[$fileIndex + 1])) {
            /**
             * @var $nextFile SplFileInfo
             */
            $nextFile = $files[$fileIndex + 1];

            $nextLog = str_replace("/", "-", $nextFile->getRelativePath()) . ".html";
        }

        $outputFilename = str_replace("/", "-", $file->getRelativePath());

        try {
            $html = $twig->render("template.twig", array
            (
                "ownName" => $ownName,
                "contactName" => $contactName,
                "messages" => array_values($messages),
                "previousLog" => $previousLog,
                "nextLog" => $nextLog
            ));

            $filesystem->dumpFile(sprintf("%s/%s/%s.html", $outputDir, $contactName, $outputFilename), $html);
        } catch (Exception $exception) {
            fwrite(STDERR, $exception->getTraceAsString());
        }

        $previousLog = $outputFilename . ".html";
    }
}