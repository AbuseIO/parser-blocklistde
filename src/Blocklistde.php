<?php

namespace AbuseIO\Parsers;

use AbuseIO\Models\Incident;

/**
 * Class Blocklistde
 * @package AbuseIO\Parsers
 */
class Blocklistde extends Parser
{
    /**
     * Create a new Blocklistde instance
     *
     * @param \PhpMimeMailParser\Parser $parsedMail phpMimeParser object
     * @param array $arfMail array with ARF detected results
     */
    public function __construct($parsedMail, $arfMail)
    {
        // Call the parent constructor to initialize some basics
        parent::__construct($parsedMail, $arfMail, $this);
    }

    /**
     * Parse attachments
     * @return array    Returns array with failed or success data
     *                  (See parser-common/src/Parser.php) for more info.
     */
    public function parse()
    {
        foreach ($this->parsedMail->getAttachments() as $attachment) {
            // Only use the Blocklistde formatted reports, skip all others
            if (preg_match(config("{$this->configBase}.parser.report_file"), $attachment->filename)) {
                if (preg_match_all('/([\w\-]+): (.*)[ ]*\r?\n/', $attachment->getContent(), $regs)) {
                    $report = array_combine($regs[1], $regs[2]);

                    // We need this field to detect the feed, so we need to check it first
                    if (!empty($report['Report-Type'])) {
                        $this->feedName = $report['Report-Type'];

                        // If report type is an alias, get the real type
                        foreach (config("{$this->configBase}.parser.aliases") as $alias => $real) {
                            if ($report['Report-Type'] == $alias) {
                                $this->feedName = $real;
                            }
                        }

                        // If feed is known and enabled, validate data and save report
                        if ($this->isKnownFeed() && $this->isEnabledFeed()) {
                            // Sanity check
                            if ($this->hasRequiredFields($report) === true) {
                                // incident has all requirements met, filter and add!
                                $report = $this->applyFilters($report);

                                $incident = new Incident();
                                $incident->source      = config("{$this->configBase}.parser.name");
                                $incident->source_id   = false;
                                $incident->ip          = $report['Source'];
                                $incident->domain      = false;
                                $incident->class       = config("{$this->configBase}.feeds.{$this->feedName}.class");
                                $incident->type        = config("{$this->configBase}.feeds.{$this->feedName}.type");
                                $incident->timestamp   = strtotime($report['Date']);
                                $incident->information = json_encode($report);

                                $this->incidents[] = $incident;

                            }
                        }
                    } else {
                        // We cannot parse this report, since we haven't detected a report_type.
                        $this->warningCount++;
                    }
                } else {
                    // We cannot parse this report, since we cant collect incident data.
                    $this->warningCount++;
                }
            } // end if: found report file to parse
        } // end foreach: loop through attachments

        return $this->success();
    }
}
