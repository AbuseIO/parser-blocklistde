<?php

namespace AbuseIO\Parsers;

use AbuseIO\Parsers\Parser;
use Ddeboer\DataImport\Reader;
use Ddeboer\DataImport\Writer;
use Ddeboer\DataImport\Filter;
use Illuminate\Filesystem\Filesystem;
use SplFileObject;
use Uuid;
use Log;

class Google extends Parser
{
    public $parsedMail;
    public $arfMail;
    public $config;

    public function __construct($parsedMail, $arfMail, $config = false)
    {
        $this->configFile = __DIR__ . '/../config/' . basename(__FILE__);
        $this->config = $config;
        $this->parsedMail = $parsedMail;
        $this->arfMail = $arfMail;

    }

    public function parse()
    {

        Log::info(
            get_class($this) . ': Received message from: ' .
            $this->parsedMail->getHeader('from') . " with subject: '" .
            $this->parsedMail->getHeader('subject') . "' arrived at parser: " .
            $this->config['parser']['name']
        );

        $events = [];

        foreach ($this->parsedMail->getAttachments() as $attachment) {

            if ($attachment->filename != 'report.txt') {
                continue;
            }

            preg_match_all('/([\w\-]+): (.*)[ ]*\r?\n/', $attachment->getContent(), $regs);
            $fields = array_combine($regs[1], $regs[2]);

            // Handle aliasses first
            foreach ($this->config['parser']['aliasses'] as $alias => $real) {
                if ($fields['Report-Type'] == $alias) {
                    $fields['Report-Type'] = $real;
                }
            }

            $feed = $fields['Report-Type'];

            if (!isset($this->config['feeds'][$feed])) {
                return $this->failed("Detected feed ${feed} is unknown. No sense in trying to parse.");
            } else {
                $feedConfig = $this->config['feeds'][$feed];
            }

            if ($feedConfig['enabled'] !== true) {
                return $this->success(
                    "Detected feed ${feed} has been disabled by configuration. No sense in trying to parse."
                );
            }


            $event = [
                'source'        => $this->config['parser']['name'],
                'ip'            => $fields['Source'],
                'class'         => $feedConfig['class'],
                'type'          => $feedConfig['type'],
                'timestamp'     => strtotime($fields['Date']),
                'information'   => json_encode($fields),
            ];

            $events[] = $event;
        }

        return $this->success($events);

    }
}
