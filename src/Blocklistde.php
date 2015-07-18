<?php

namespace AbuseIO\Parsers;

use Ddeboer\DataImport\Reader;
use Ddeboer\DataImport\Writer;
use Ddeboer\DataImport\Filter;
use Log;
use ReflectionClass;

class Blocklistde extends Parser
{
    public $parsedMail;
    public $arfMail;

    /**
     * Create a new Blocklistde instance
     */
    public function __construct($parsedMail, $arfMail)
    {
        $this->parsedMail = $parsedMail;
        $this->arfMail = $arfMail;
    }

    /**
     * Parse attachments
     * @return Array    Returns array with failed or success data
     *                  (See parser-common/src/Parser.php) for more info.
     */
    public function parse()
    {
        // Generalize the local config based on the parser class name.
        $reflect = new ReflectionClass($this);
        $configBase = 'parsers.' . $reflect->getShortName();

        Log::info(
            get_class($this) . ': Received message from: ' .
            $this->parsedMail->getHeader('from') . " with subject: '" .
            $this->parsedMail->getHeader('subject') . "' arrived at parser: " .
            config("{$configBase}.parser.name")
        );

        $events = [ ];

        foreach ($this->parsedMail->getAttachments() as $attachment) {
            if ($attachment->filename != 'report.txt') {
                continue;
            }

            preg_match_all('/([\w\-]+): (.*)[ ]*\r?\n/', $attachment->getContent(), $regs);
            $fields = array_combine($regs[1], $regs[2]);

            // Handle aliasses first
            foreach (config("{$configBase}.parser.aliases") as $alias => $real) {
                if ($fields['Report-Type'] == $alias) {
                    $fields['Report-Type'] = $real;
                }
            }

            $feedName = $fields['Report-Type'];

            if (empty(config("{$configBase}.feeds.{$feedName}"))) {
                return $this->failed("Detected feed '{$feedName}' is unknown.");
            }

            if (config("{$configBase}.feeds.{$feedName}.enabled") !== true) {
                continue;
            }

            $event = [
                'source'        => config("{$configBase}.parser.name"),
                'ip'            => $fields['Source'],
                'domain'        => false,
                'uri'           => false,
                'class'         => config("{$configBase}.feeds.{$feedName}.class"),
                'type'          => config("{$configBase}.feeds.{$feedName}.type"),
                'timestamp'     => strtotime($fields['Date']),
                'information'   => json_encode($fields),
            ];

            $events[] = $event;
        }

        return $this->success($events);
    }
}
