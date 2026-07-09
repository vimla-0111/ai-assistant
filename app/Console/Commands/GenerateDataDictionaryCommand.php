<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ai:generate-data-dictionary', description: 'Generate a compact data dictionary JSON from the COLUMNS.json source file.')]
class GenerateDataDictionaryCommand extends Command
{
    /**
     * Tables that belong to infrastructure / the ai-assistant app itself
     * and are NOT part of iresource_db — skip them entirely.
     *
     * @var string[]
     */
    private const array SKIP_TABLES = [
        'activity_log',
        'ai_conversations',
        'ai_usage_logs',
        'pending_ai_actions',
        'failed_jobs',
        'jobs',
        'migrations',
        'password_resets',
        'extras',
    ];

    /**
     * Human-readable table descriptions.
     *
     * @var array<string, string>
     */
    private const array TABLE_DESCRIPTIONS = [
        'users' => 'All system users: admins, HR, interviewers, and candidates (interviewees).',
        'departments' => 'Hiring departments within the organisation.',
        'designations' => 'Job titles/designations per department.',
        'levels' => 'Seniority or experience levels (Junior, Mid, Senior, etc.).',
        'colleges' => 'Academic institutions from which candidates are sourced.',
        'courses' => 'Degree or course types completed by candidates.',
        'requisitions' => 'Open job requisitions raised by team leads or managers.',
        'candidate_status_history' => 'Audit trail of each candidate\'s pipeline stage changes.',
        'hr_candidates' => 'Maps HR officers to the candidates they manage.',
        'interviewers_candidates' => 'Maps interviewers to the candidates assigned to them.',
        'exams' => 'Technical assessments/tests assigned to candidates.',
        'exam_question_banks' => 'Links exams to question banks with per-bank question counts.',
        'question_banks' => 'Named groups of questions by department and level.',
        'questions' => 'Individual MCQ and descriptive questions used in exams.',
        'tests' => 'Candidate exam attempt records — one row per question answered.',
        'user_schedules' => 'Interview schedule slots linking candidates to interviewers.',
        'user_schedules_old' => 'Legacy interview schedule records (superseded by user_schedules).',
        'user_experiences' => 'Work experience history entries for candidates.',
        'user_qualifications' => 'Academic qualification records for candidates.',
        'user_families' => 'Family member details provided by candidates.',
        'user_references' => 'Professional reference contacts provided by candidates.',
        'user_inquiry_forms' => 'Pre-registration inquiry form data submitted by candidates.',
        'schedules' => 'Email/notification schedule subscription list.',
        'send_updates' => 'Update email subscription preferences.',
        'site_settings' => 'Application-wide configuration: limits, timers, registration toggle.',
        'roles' => 'Spatie RBAC role definitions.',
        'permissions' => 'Spatie RBAC permission definitions.',
        'role_has_permissions' => 'Pivot: which permissions belong to which role.',
        'model_has_roles' => 'Pivot: which roles are assigned to which model instance.',
        'model_has_permissions' => 'Pivot: direct permissions assigned to a model instance.',
    ];

    /**
     * Domain-specific column comments.
     * Key format: "table.column" or "*column" for cross-table patterns.
     *
     * @var array<string, string>
     */
    private const array COLUMN_COMMENTS = [
        // users — critical business columns
        'users.active' => '1=inactive/pending, 2=active. Always use WHERE active=2 to filter active users.',
        'users.role' => 'User type: admin | employee | interviewer | hr | interviewee (candidate).',
        'users.status' => 'Registration or account status string.',
        'users.candidate_status' => 'Current pipeline stage for interviewee users. See candidate_status_history for full history.',
        'users.is_teamlead' => '1 if this user is a team lead, 0 otherwise.',
        'users.is_requester' => '1 if this user can raise job requisitions.',
        'users.interview_percentage' => 'Aggregate interview score percentage for the candidate.',
        'users.practical_percentage' => 'Practical/coding round score percentage.',
        'users.technical_percentage' => 'Technical round score percentage.',
        'users.communication' => 'Communication skill rating (interviewer-assessed).',
        'users.attitude' => 'Attitude rating (interviewer-assessed).',
        'users.logical' => 'Logical reasoning rating (interviewer-assessed).',
        'users.last_salary' => 'Candidate\'s last drawn salary (CTC).',
        'users.expected_salary' => 'Candidate\'s expected salary (CTC).',

        // candidate_status_history
        'candidate_status_history.status' => '0=Applied, 1=Shortlisted, 2=Test Scheduled, 3=Interview, 4=HR Round, 5=Selected, 6=Rejected, 7=On Hold.',

        // exams
        'exams.type' => 'exam = direct question set; question_bank = questions drawn from a bank.',
        'exams.status' => '0=inactive, 1=active.',
        'exams.assign_tl_id' => 'JSON array of team lead user IDs assigned to review this exam.',

        // questions
        'questions.a' => 'MCQ option A.',
        'questions.b' => 'MCQ option B.',
        'questions.c' => 'MCQ option C.',
        'questions.d' => 'MCQ option D.',
        'questions.e' => 'MCQ option E (if applicable).',
        'questions.answer' => 'Correct option key: a | b | c | d.',
        'questions.multi_answers' => 'Comma-separated correct option keys for multi-select questions.',
        'questions.question_type' => 'Type: mcq | multi_select | descriptive.',
        'questions.status' => '0=inactive, 1=active.',

        // tests (exam attempt records)
        'tests.correct' => '1=candidate answered correctly, 0=incorrect.',
        'tests.reviewed' => '0=not reviewed, 1=reviewed (relevant for descriptive answers).',

        // requisitions
        'requisitions.position' => 'Total number of vacancies for this requisition.',
        'requisitions.position_still_open' => 'Remaining unfilled vacancies.',
        'requisitions.position_closed' => 'Number of vacancies already filled.',
        'requisitions.status' => 'Requisition lifecycle: pending | approved | rejected | closed.',
        'requisitions.salary_criteria' => 'Maximum salary budget for this position.',

        // user_schedules
        'user_schedules.interviewers' => 'JSON array of interviewer user IDs for this slot.',
        'user_schedules.interview_conducted' => '0=not yet conducted, 1=interview completed.',

        // colleges
        'colleges.cutoff' => 'Minimum aggregate percentage cutoff for candidate eligibility.',
        'colleges.is_active' => '1=college is active for sourcing, 0=inactive.',

        // departments
        'departments.is_active' => '1=department is active, 0=inactive.',

        // site_settings
        'site_settings.question_limit' => 'Maximum number of questions per exam session.',
        'site_settings.time_limit' => 'Default exam time limit in minutes.',
        'site_settings.register_link_status' => '0=registration link hidden, 1=registration link visible.',
        'site_settings.exam_ss_timer' => 'Screenshot timer interval during exam (minutes).',

        // send_updates
        'send_updates.status' => '1=subscribed, 0=unsubscribed.',
        'send_updates.want_to_share_weekly_report' => '1=opted in to weekly reports.',

        // user_inquiry_forms
        'user_inquiry_forms.is_salary_negotiable' => '1=salary is negotiable, 0=fixed expectation.',
        'user_inquiry_forms.ready_to_relocate' => '1=open to relocation.',
        'user_inquiry_forms.appeared_earlier' => '1=candidate has appeared for an interview here before.',
        'user_inquiry_forms.send_inquiry_form' => '1=inquiry form sent to candidate.',
        'user_inquiry_forms.send_details_to_tl' => '1=candidate details forwarded to team lead.',
    ];

    public function handle(): int
    {
        $sourcePath = 'ai/source/COLUMNS.json';
        $outputPath = 'ai/context/data_dictionary.json';

        $this->info('Reading source: '.$sourcePath);

        $raw = Storage::disk('local')->get($sourcePath);

        if (! $raw) {
            $this->error("Source file not found at storage/app/private/{$sourcePath}");

            return self::FAILURE;
        }

        $parsed = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Failed to parse JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        // The export wraps everything: find the entry with "data" array
        $rows = collect($parsed)
            ->first(fn ($entry) => isset($entry['data']) && is_array($entry['data']))['data'] ?? [];

        if (empty($rows)) {
            $this->error('No column rows found in source file.');

            return self::FAILURE;
        }

        $this->info('Processing '.count($rows).' column rows...');

        $dictionary = $this->buildDictionary($rows);

        $json = json_encode($dictionary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        Storage::disk('local')->put($outputPath, $json);

        $tableCount = count($dictionary);
        $colCount = array_sum(array_map(fn ($t) => count($t['cols']), $dictionary));

        $this->info("Done. {$tableCount} tables, {$colCount} columns → storage/app/private/{$outputPath}");

        return self::SUCCESS;
    }

    /**
     * Build the compact dictionary grouped by table.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{desc: string, cols: array<int, array<string, mixed>>}>
     */
    private function buildDictionary(array $rows): array
    {
        $dictionary = [];

        foreach ($rows as $row) {
            $table = $row['TABLE_NAME'] ?? '';

            if ($table === '' || in_array($table, self::SKIP_TABLES, true)) {
                continue;
            }

            if (! isset($dictionary[$table])) {
                $dictionary[$table] = [
                    'desc' => self::TABLE_DESCRIPTIONS[$table] ?? ucwords(str_replace('_', ' ', $table)).' records.',
                    'cols' => [],
                ];
            }

            $dictionary[$table]['cols'][] = $this->compactColumn($table, $row);
        }

        return $dictionary;
    }

    /**
     * Build a compact column entry — omit keys that carry no information
     * and only include a comment when it adds meaningful domain context.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function compactColumn(string $table, array $row): array
    {
        $name = $row['COLUMN_NAME'];
        $entry = [
            'col' => $name,
            'type' => $row['COLUMN_TYPE'],
        ];

        // Include key only when set (PRI, UNI, MUL)
        if (! empty($row['COLUMN_KEY'])) {
            $entry['key'] = $row['COLUMN_KEY'];
        }

        // Mark nullable only when YES (omit for NOT NULL — the common case)
        if ($row['IS_NULLABLE'] === 'YES') {
            $entry['null'] = true;
        }

        // Include default only when it carries meaning (not null or CURRENT_TIMESTAMP)
        $default = $row['COLUMN_DEFAULT'];
        if ($default !== null && $default !== 'CURRENT_TIMESTAMP') {
            $entry['default'] = $default;
        }

        // Domain comment — explicit map first, then auto-generated fallback only for
        // columns where the name alone is ambiguous (status flags, enums, scores)
        $comment = self::COLUMN_COMMENTS["{$table}.{$name}"] ?? $this->autoComment($name, $row['COLUMN_TYPE']);

        if ($comment !== null) {
            $entry['comment'] = $comment;
        }

        return $entry;
    }

    /**
     * Generate a minimal fallback comment only for patterns where the name
     * alone doesn't convey the value domain. Returns null for obvious columns.
     */
    private function autoComment(string $name, string $type): ?string
    {
        // Enum types — always show the allowed values
        if (str_starts_with($type, 'enum(')) {
            $values = trim(substr($type, 5, -1), "'");
            $values = str_replace("','", ' | ', $values);

            return "Allowed: {$values}.";
        }

        // Foreign keys — clarify the referenced table
        if (str_ends_with($name, '_id') && $name !== 'id') {
            $ref = str_replace('_id', '', $name);

            return "FK → {$ref}.id";
        }

        // No comment needed for self-explanatory columns
        return null;
    }
}
