<?php

namespace AbuseIO\Parsers;

class Blocklistde extends Parser
{
    /**
     * Create a new Blocklistde instance
     */
    public function __construct($parsedMail, $arfMail)
    {
        // Call the parent constructor to initialize some basics
        parent::__construct($parsedMail, $arfMail, $this);
    }

    /**
     * Parse attachments
     * @return Array    Returns array with failed or success data
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
                                // Event has all requirements met, filter and add!
                                $report = $this->applyFilters($report);

                                $this->events[] = [
                                    'source' => config("{$this->configBase}.parser.name"),
                                    'ip' => $report['Source'],
                                    'domain' => false,
                                    'uri' => false,
                                    'class' => config("{$this->configBase}.feeds.{$this->feedName}.class"),
                                    'type' => config("{$this->configBase}.feeds.{$this->feedName}.type"),
                                    'timestamp' => strtotime($report['Date']),
                                    'information' => json_encode($report),
                                ];
                            }
                        }
                    } else {
                        // We cannot parse this report, since we haven't detected a report_type.
                        $this->warningCount++;
                    }
                } else {
                    // We cannot parse this report, since we cant collect event data.
                    $this->warningCount++;
                }
            } // end if: found report file to parse
        } // end foreach: loop through attachments

        return $this->success();
    }
}
