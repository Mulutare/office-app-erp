<?php

declare(strict_types=1);

return [
    'version' => '067',
    'description' => 'Register the Finance posting-period prerequisite error',
    'preflight' => static function (\PDO $connection): string {
        $statement = $connection->prepare(
            "SELECT COUNT(*) FROM app_error_catalog WHERE error_code='FIN-PER-001'"
        );
        $statement->execute();
        return (int) $statement->fetchColumn() === 0 ? 'apply' : 'baseline';
    },
    'statements' => [
        <<<'SQL'
INSERT INTO app_error_catalog(error_code,module,title,cause,suggested_action,severity,user_visible,log_incident,active)
VALUES(
 'FIN-PER-001',
 'finance',
 'No open accounting period for posting date',
 'No open accounting period covers the required posting date.',
 'Create or reopen an accounting period that includes the posting date, then retry the transaction.',
 'warning',
 TRUE,
 TRUE,
 TRUE
)
SQL,
    ],
];
