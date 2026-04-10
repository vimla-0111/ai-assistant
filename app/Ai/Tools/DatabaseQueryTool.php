<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DatabaseQueryTool implements Tool
{
    private const array ALLOWED_COLUMNS =
    [
        'users' => ['id', 'department_id', 'college_id', 'course_id', 'level_id', 'requisition_id', 'name', 'email', 'role', 'gender', 'dob', 'address', 'city', 'state', 'country', 'mobile', 'status', 'active', 'candidate_status', 'last_salary', 'expected_salary', 'interview_percentage', 'practical_percentage', 'technical_percentage', 'communication', 'attitude', 'logical', 'is_teamlead', 'is_requester', 'last_login_at', 'created_at', 'updated_at', 'deleted_at'],
        'departments' => ['id', 'name', 'slug', 'icon', 'is_active', 'created_at', 'updated_at'],
        'designations' => ['id', 'department_id', 'name', 'slug', 'created_at', 'updated_at'],
        'levels' => ['id', 'name', 'created_at', 'updated_at'],
        'colleges' => ['id', 'name', 'cutoff', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
        'courses' => ['id', 'name', 'created_at', 'updated_at', 'deleted_at'],
        'requisitions' => ['id', 'user_id', 'department_id', 'level_id', 'designation', 'position', 'experience', 'position_still_open', 'position_closed', 'status', 'priority_criteria', 'reporting_manager', 'educational_qualification', 'responsibility', 'skills_required', 'required_tech_skills', 'salary_criteria', 'opened_at', 'closed_at', 'submitted_at', 'approve_disapprove_by', 'created_at', 'updated_at'],
        'candidate_status_history' => ['id', 'candidate_id', 'status', 'created_at', 'updated_at'],
        'hr_candidates' => ['id', 'hr_id', 'candidate_id', 'created_at', 'updated_at'],
        'interviewers_candidates' => ['id', 'interviewer_id', 'candidate_id', 'created_at', 'updated_at'],
        'exams' => ['id', 'department_id', 'name', 'assign_tl_id', 'duration', 'number_of_questions', 'guidance', 'type', 'expertise_level', 'status', 'created_at', 'updated_at', 'deleted_at'],
        'exam_question_banks' => ['id', 'exam_id', 'question_bank_id', 'level_id', 'department_id', 'number_of_question', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
        'question_banks' => ['id', 'name', 'level_id', 'department_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
        'questions' => ['id', 'department_id', 'exam_id', 'level_id', 'question', 'question_type', 'a', 'b', 'c', 'd', 'e', 'answer', 'multi_answers', 'more_info', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'],
        'tests' => ['id', 'user_id', 'department_id', 'level_id', 'question_id', 'answer', 'multiple_answer', 'descriptive_answer', 'correct', 'reviewed', 'created_at', 'updated_at'],
        'user_schedules' => ['id', 'user_id', 'scheduler_id', 'interviewers', 'interview_date_time', 'review', 'interview_conducted', 'created_at', 'updated_at'],
        'user_schedules_old' => ['id', 'user_id', 'scheduled_1_with', 'scheduled_2_with', 'scheduled_3_with', 'scheduled_4_with', 'scheduled_5_with', 'scheduled_6_with', 'scheduled_date_time_1', 'scheduled_date_time_2', 'scheduled_date_time_3', 'scheduled_date_time_4', 'scheduled_date_time_5', 'scheduled_date_time_6', 'review_1', 'review_2', 'review_3', 'review_4', 'review_5', 'review_6', 'interview_conducted', 'created_at', 'updated_at'],
        'user_experiences' => ['id', 'user_id', 'organisation_name', 'designation', 'from_month', 'from_year', 'to_month', 'to_year', 'ctc', 'reason_for_leaving', 'created_at', 'updated_at', 'deleted_at'],
        'user_qualifications' => ['id', 'user_id', 'degree', 'university', 'specialisation', 'passing_year', 'percentage', 'achievements', 'created_at', 'updated_at', 'deleted_at'],
        'user_families' => ['id', 'user_id', 'name', 'relation', 'education', 'occupation', 'age', 'salary', 'created_at', 'updated_at', 'deleted_at'],
        'user_references' => ['id', 'user_id', 'name', 'designation', 'organisation', 'email', 'contact', 'created_at', 'updated_at', 'deleted_at'],
        'user_inquiry_forms' => ['id', 'user_id', 'email', 'token', 'total_relevant_experience', 'current_ctc', 'expected_ctc', 'is_salary_negotiable', 'when_can_you_join_us', 'reason_for_job_change', 'ready_to_relocate', 'appeared_earlier', 'spi', 'semester', 'education', 'linkedin', 'git', 'from_where', 'send_inquiry_form', 'send_details_to_tl', 'created_at', 'updated_at'],
        'schedules' => ['id', 'name', 'email', 'type', 'created_at', 'updated_at'],
        'send_updates' => ['id', 'name', 'email', 'status', 'want_to_share_weekly_report', 'sent_other_emails', 'created_at', 'updated_at'],
        'site_settings' => ['id', 'title', 'email', 'phone_1', 'phone_2', 'copy_right', 'question_limit', 'time_limit', 'register_link_status', 'exam_ss_timer', 'created_at', 'updated_at'],
        'roles' => ['id', 'name', 'guard_name', 'created_at', 'updated_at'],
        'permissions' => ['id', 'name', 'guard_name', 'created_at', 'updated_at'],
        'role_has_permissions' => ['role_id', 'permission_id'],
        'model_has_roles' => ['role_id', 'model_type', 'model_id'],
        'model_has_permissions' => ['permission_id', 'model_type', 'model_id'],
    ];

    private const array ALLOWED_OPERATORS = [
        '=',
        '!=',
        '<',
        '>',
        '<=',
        '>=',
        'like',
        'and',
        'or',
    ];

    private const int MAX_ROWS = 10;

    private const int MAX_OUTPUT_LENGTH = 1000; // characters

    private const array REDACTED_SUFFIXES = [
        '_token',
        '_secret',
        '_password',
        '_key',
    ];

    protected function redact(array $row): array
    {
        return collect($row)
            ->map(fn(mixed $value, string $column): mixed => Str::endsWith(
                Str::lower($column),
                self::REDACTED_SUFFIXES
            ) ? '[REDACTED]' : $value)
            ->all();
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        //        return 'Executes a read-only MySQL SELECT query and returns the results.
        // Use this to answer user questions by querying the database.
        // Input: a valid MySQL SELECT statement.
        // Output: array of rows or an error message.
        // Never use this for INSERT, UPDATE, DELETE, or DDL statements.';
        return Storage::disk('local')->get('ai/context/database_schema.txt');
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        Log::info('inside DBtool handler');
        $table = (string) $request->string('table');
        $columns = $request->array('columns');
        $allowed = Arr::get(self::ALLOWED_COLUMNS, $table, []);

        Log::info('DatabaseQueryTool invoked', ['table' => $table, 'columns' => $columns, 'filters' => $request['where'] ?? []]);

        // Reject any column not in the allowlist
        if ($error = $this->validateColumns($columns, $allowed, $table)) {
            return $error;
        }

        // Build the query with the query builder
        $query = DB::connection('iresource_db')->table($table)
            ->select($columns)
            ->limit(min($request->integer('limit', 10), self::MAX_ROWS));

        // SELECT COUNT(*) AS count FROM users WHERE role = 'interviewee'
        // Validate and apply each filter condition
        if ($error = $this->applyFilters($query, collect($request['where'] ?? []), $allowed, $table)) {
            return $error;
        }

        // Run the query, redact sensitive columns, cap the output size
        $rows = $query->get()
            ->map(fn(object $row): array => $this->redact((array) $row))
            ->all();

        return $this->formatOutput($rows);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('Table name to query.')
                ->required(),

            'columns' => $schema->array()
                ->items($schema->string())
                ->description('Column names to select.')
                ->required(),

            'where' => $schema->array()
                ->items(
                    $schema->object([
                        'column' => $schema->string()
                            ->description('Column name to filter on.')
                            ->required(),
                        'operator' => $schema->string()
                            ->description('Comparison operator (=, !=, <, >, <=, >=, like).')
                            ->default('='),
                        'value' => $schema->string()
                            ->description('Filter value.')
                            ->required(),
                    ])
                )
                ->description('Filter conditions.')
                ->default([]),

            'limit' => $schema->integer()
                ->description('Max rows to return.')
                ->default(10),
        ];
    }

    private function validateColumns(array $columns, array $allowed, string $table): ?string
    {
        foreach ($columns as $column) {
            if (! in_array($column, $allowed)) {
                Log::warning('DatabaseQueryTool: Column not allowed', ['table' => $table, 'column' => $column]);

                return "Error: Column '{$column}' is not allowed in table '{$table}'.";
            }
        }

        return null;
    }

    private function applyFilters($query, $filters, array $allowed, string $table): ?string
    {
        foreach ($filters as $filter) {
            $column = Arr::get($filter, 'column');
            $operator = Arr::get($filter, 'operator', '=');
            $value = Arr::get($filter, 'value');

            if (! in_array($operator, self::ALLOWED_OPERATORS)) {
                Log::warning('DatabaseQueryTool: Operator not allowed', ['operator' => $operator]);

                return "Error: Operator '{$operator}' is not allowed.";
            }

            $query->where($column, $operator, $value);
        }

        return null;
    }

    protected function formatOutput(array $rows): string
    {
        $result = ['rows' => $rows, 'count' => count($rows), 'truncated' => false];
        $json = json_encode($result);

        if (Str::length($json) <= self::MAX_OUTPUT_LENGTH) {
            return $json;
        }

        // We know the total size and the row count, so we can
        // estimate how many rows fit within our budget.
        $averageRowSize = Str::length($json) / count($rows);
        $rowsThatFit = (int) floor(self::MAX_OUTPUT_LENGTH / $averageRowSize);

        // Slice to 85% of the estimate — row sizes vary,
        // so we leave headroom to avoid overshooting.
        $safeLimit = max(1, (int) ($rowsThatFit * 0.85));
        $rows = array_slice($rows, 0, $safeLimit);

        $result = ['rows' => $rows, 'count' => count($rows), 'truncated' => true];
        $json = json_encode($result);

        // If the estimate was still too generous (e.g. one row
        // has a huge text column), halve until it fits.
        while (Str::length($json) > self::MAX_OUTPUT_LENGTH && count($rows) > 1) {
            $rows = array_slice($rows, 0, intdiv(count($rows), 2));
            $result = ['rows' => $rows, 'count' => count($rows), 'truncated' => true];
            $json = json_encode($result);
        }

        return $json;
    }
}
