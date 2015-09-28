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
            $report = array_combine($regs[1], $regs[2]);

            // We need this field to detect the feed, so we need to check it first
            if (empty($report['Report-Type'])) {
                return $this->failed(
                    "Unable to detect feed because the required field Report-Type is missing."
                );
            }

            // Handle aliasses first
            foreach (config("{$configBase}.parser.aliases") as $alias => $real) {
                if ($report['Report-Type'] == $alias) {
                    $report['Report-Type'] = $real;
                }
            }

            $this->feedName = $report['Report-Type'];

            if (!$this->isKnownFeed()) {
                return $this->failed(
                    "Detected feed {$this->feedName} is unknown."
                );
            }

            if (!$this->isEnabledFeed()) {
                continue;
            }

            if (!$this->hasRequiredFields($report)) {
                return $this->failed(
                    "Required field {$this->requiredField} is missing or the config is incorrect."
                );
            }

            $report = $this->applyFilters($report);

            $event = [
                'source'        => config("{$configBase}.parser.name"),
                'ip'            => $report['Source'],
                'domain'        => false,
                'uri'           => false,
                'class'         => config("{$configBase}.feeds.{$this->feedName}.class"),
                'type'          => config("{$configBase}.feeds.{$this->feedName}.type"),
                'timestamp'     => strtotime($report['Date']),
                'information'   => json_encode($report),
            ];

            $events[] = $event;
        }

        return $this->success($events);
    }
}
