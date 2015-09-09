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

            // We need this field to detect the feed, so we need to check it first
            if (empty($fields['Report-Type'])) {
                return $this->failed(
                    "Unable to detect feed because the required field Report-Type is missing."
                );
            }

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

            $columns = array_filter(config("{$configBase}.feeds.{$feedName}.fields"));
            if (count($columns) > 0) {
                foreach ($columns as $column) {
                    if (!isset($fields[$column])) {
                        return $this->failed(
                            "Required field ${column} is missing in the report or config is incorrect."
                        );
                    }
                }
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

        if (empty($events)) {
            return $this->failed(
                config("{$configBase}.parser.name") .
                " was unabled to collect any event(s) from the received email. Either corrupt sample or invalid config"
            );
        }

        return $this->success($events);
    }
}
