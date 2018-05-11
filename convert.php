#! /usr/bin/env php
<?php
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

require_once __DIR__ . "/vendor/autoload.php";

function getMessages(SplFileInfo $file)
{
    // Trillian does not include a root element in the XML files
    $xml = "<root>" . $file->getContents() . "</root>";

    $dom = new DOMDocument;

    $dom->loadXML($xml);

    return $dom->getElementsByTagName("message");
}

function timestampToDate($timestamp)
{
    $date = new DateTime;

    $date->setTimestamp($timestamp / 1000);// Timestamp is in milliseconds

    return $date;
}

function getMessageFromElement(DOMElement $element)
{
    //<message time="1311612005103" type="outgoing_privateMessage" text="some%20message"/>
    $date = timestampToDate($element->getAttribute("time"));
    $type = $element->getAttribute("type");
    $text = rawurldecode($element->getAttribute("text"));

    $messageId = sha1(sprintf("%d/%s/%s", $date->getTimestamp(), $type, $text));

    return array
    (
        $date,
        $type,
        $text,
        $messageId
    );
}

function getFilenameFromDate(DateTime $date)
{
    //2011-07-25.184005+0200CEST
    return $date->format("Y-m-d.HisOT") . ".html";
}

if (!isset($argv[3])) {
    fwrite(STDERR, "Usage: " . $argv[0] . " <own username> <path to Trillian logs dir> <output dir> [<template>]\n");
    exit(1);
}

$ownName = $argv[1];
$sourceDir = $argv[2];
$outputDir = $argv[3];
$template = $argv[4] ?? "bootstrap";

$loader = new Twig_Loader_Filesystem(__DIR__ . "/templates");
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
    $perDayMessages = array();

    foreach ($files as $file) {
        foreach (getMessages($file) as $messageElement) {
            /**
             * @var $date DateTime
             */
            list($date, $type, $text, $messageId) = getMessageFromElement($messageElement);

            $day = $date->format("Y-m-d");

            if (!isset($perDayMessages[$day])) {
                $perDayMessages[$day] = array();
            }

            if (isset($perDayMessages[$day][$messageId])) {
                fwrite(STDERR, sprintf("Skipping duplicate message: %s - %s\n", $date->format("r"), $text));
                continue;
            }

            $perDayMessages[$day][$messageId] = array($date, $type, $text);
        }
    }

    // Remove messageId key
    $perDayMessages = array_map("array_values", $perDayMessages);

    $perDayMessages = array_values($perDayMessages);

    $previousLog = null;

    foreach ($perDayMessages as $dayIndex => $dayMessages) {
        $messages = array();
        $firstDate = null;

        foreach ($dayMessages as $message) {
            list($date, $type, $text) = $message;

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

            if ($firstDate === null) {
                $firstDate = $date;
            }

            $messages[] = array
            (
                "date" => $date,
                "user" => $user,
                "type" => $type,
                "text" => $text
            );
        }

        if (empty($messages)) {
            fwrite(STDERR, "No messages found, skipping\n");
            continue;
        }

        if (isset($perDayMessages[$dayIndex + 1])) {
            $nextDate = $perDayMessages[$dayIndex + 1][0][0];

            $nextLog = getFilenameFromDate($nextDate);
        } else {
            $nextLog = null;
        }

        $outputFilename = getFilenameFromDate($firstDate);

        try {
            $html = $twig->render(sprintf("%s.twig", $template), array
            (
                "ownName" => $ownName,
                "contactName" => $contactName,
                "messages" => array_values($messages),
                "previousLog" => $previousLog,
                "nextLog" => $nextLog
            ));

            $filesystem->dumpFile(sprintf("%s/%s/%s", $outputDir, $contactName, $outputFilename), $html);
        } catch (Exception $exception) {
            fwrite(STDERR, $exception->getTraceAsString());
        }

        $previousLog = $outputFilename;
    }
}