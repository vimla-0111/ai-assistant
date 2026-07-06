<?php

namespace App\Ai\Agents;

use App\Ai\Tools\DatabaseQueryTool;
use App\Ai\Tools\QuiryBuilderTool;
use App\Ai\Tools\ResumeSearchTool;
use App\Models\AgentConversationMessage;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
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

    private $messageLimit = 5;

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return config('ai.prompt_path') && Storage::disk('local')->exists(config('ai.prompt_path'))
            ? Storage::disk('local')->get(config('ai.prompt_path')).Storage::disk('local')->get('ai/context/database_schema.txt')
            : 'You are a smart database assistant. You have access to tools that run queries and return results. '
            .'After using tools to gather information, always provide a clear, concise summary of the results in your response. '
            .'Return the actual data from your tool results, formatted clearly for the user.';
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

    /**
     * Get the agent's structured output schema definition.
     */
    // public function schema(JsonSchema $schema): array
    // {
    //     return [
    //         'value' => $schema->string()->required(),
    //     ];
    // }
}
