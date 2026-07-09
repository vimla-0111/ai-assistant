<?php

namespace App\Ai\Agents;

use App\Ai\Tools\QuiryBuilderTool;
use App\Ai\Tools\ResumeSearchTool;
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

#[MaxSteps(5)]
class ProjectAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    private int $messageLimit = 2;

    private string $schemaContext = '';

    public function __construct(public User $user) {}

    /**
     * Attach relevant schema context to be injected into the instructions.
     * Returns the same instance for fluent chaining.
     */
    public function withSchemaContext(string $context): static
    {
        $this->schemaContext = $context;

        return $this;
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $base = 'You are a smart assistant with access to two tools: a resume search tool and a database query builder tool. '
            .'Use the resume search tool to answer questions about candidate resumes, skills, experience, and education. '
            .'Use the database query builder tool to answer questions about application data, records, and table values. '
            .'Always use tools when needed, do not invent facts, and summarize the actual tool results clearly and concisely.';

        if ($this->schemaContext !== '') {
            $base .= "\n\nThe following database tables are most relevant to the user's query. "
                ."Use ONLY these tables and their columns when building database queries:\n\n"
                .$this->schemaContext;
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
        return [new QuiryBuilderTool, new ResumeSearchTool];
    }
}
