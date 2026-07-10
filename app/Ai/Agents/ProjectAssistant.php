<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ContextTool;
use App\Ai\Tools\QueryBuilderTool;
use App\Models\AgentConversationMessage;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(7)]
class ProjectAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    private int $messageLimit = 2;

    /**
     * Context pre-fetched by AgentController and injected into the first prompt.
     * May contain schema definitions, resume excerpts, or be empty for greetings.
     */
    private string $initialContext = '';

    public function __construct(public User $user) {}

    /**
     * Attach the pre-resolved context to be injected into the instructions.
     * Returns the same instance for fluent chaining.
     */
    public function withInitialContext(string $context): static
    {
        $this->initialContext = $context;

        return $this;
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $base = 'You are a smart assistant that helps manage HR operations, candidate resumes, and application data. '
            .'Always use the provided tools to fetch real data instead of inventing facts. Summarize tool results clearly and concisely. '
            .'CRITICAL: Do not execute a tool more than twice for a single user query. If a tool returns an empty result (e.g. empty array or "No results"), ACCEPT that the data does not exist. DO NOT guess other tables or columns to retry. Immediately tell the user the data was not found. '
            .'If the user\'s request is ambiguous between resume search and database query, ask the user to clarify. '
            .'IMPORTANT DB RULES: When querying ENUM columns (like `active`), ALWAYS use string values (e.g., ->where(\'active\', \'2\')) instead of integers. MySQL interprets integers on ENUM columns as 1-based indexes, returning incorrect records! '
            .'CONTEXT TOOL USAGE: If the context provided below is insufficient or missing for the task, call the ContextTool to fetch additional database schema (type="schema"), '
            .'resume content (type="resume"), or both simultaneously (type="both"). For type="both", provide separate focused queries for each source via query_schema and query_resume.';

        if ($this->initialContext !== '') {
            $base .= "\n\nThe following context has been pre-fetched for this query. Use it to answer directly or to build an accurate database query:\n\n"
                .$this->initialContext;
        }

        return $base;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return AgentConversationMessage::where('user_id', $this->user->id)
            ->latest()
            ->limit($this->messageLimit)
            ->get()
            ->reverse()
            ->map(function ($message) {
                return new Message($message->role, $message->content);
            })->all();
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [app(ContextTool::class), new QueryBuilderTool];
    }
}
