<?php
$ts = 1788419700;
echo 'Cron next fire (UTC): ' . gmdate('Y-m-d H:i:s', $ts) . PHP_EOL;
echo 'Cron next fire (Asia/Nicosia): ' . (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Nicosia'))->format('Y-m-d H:i:s') . PHP_EOL;
