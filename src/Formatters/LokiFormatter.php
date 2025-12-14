<?php

namespace Omniboost\LaravelLoggingLoki\Formatters;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

class LokiFormatter extends NormalizerFormatter
{
    /**
     * {@inheritdoc}
     */
    public function format(LogRecord $record): string
    {
        // Normalize the record to handle objects, exceptions, etc.
        $normalized = parent::format($record);

        // Build a simple text format for Loki
        $output = sprintf(
            '[%s] %s - %s',
            strtoupper($normalized['level_name']),
            $record->datetime->format(\DateTime::RFC3339_EXTENDED),
            $normalized['message']
        );

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function formatBatch(array $records): array
    {
        $formatted = [];
        foreach ($records as $record) {
            $formatted[] = $this->format($record);
        }
        return $formatted;
    }
}
