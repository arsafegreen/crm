// Copyright (c) Microsoft. All rights reserved.

using HomeAutomation.Memory;
using HomeAutomation.Options;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Options;
using Microsoft.SemanticKernel;
using Microsoft.SemanticKernel.ChatCompletion;
using Microsoft.SemanticKernel.Connectors.OpenAI;

namespace HomeAutomation;

/// <summary>
/// Actual code to run.
/// </summary>
internal sealed class Worker(
    IHostApplicationLifetime hostApplicationLifetime,
    [FromKeyedServices("HomeAutomationKernel")] Kernel kernel,
    IChatHistoryStore chatHistoryStore,
    IOptions<MemoryOptions> memoryOptions,
    IOptions<AgentOptions> agentOptions,
    RagSearchService ragSearchService) : BackgroundService
{
    private readonly IHostApplicationLifetime _hostApplicationLifetime = hostApplicationLifetime;
    private readonly Kernel _kernel = kernel;
    private readonly IChatHistoryStore _chatHistoryStore = chatHistoryStore;
    private readonly MemoryOptions _memoryOptions = memoryOptions.Value;
    private readonly AgentOptions _agentOptions = agentOptions.Value;
    private readonly RagSearchService _ragSearchService = ragSearchService;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        // Get chat completion service
        var chatCompletionService = _kernel.GetRequiredService<IChatCompletionService>();

        // Enable auto function calling
        OpenAIPromptExecutionSettings openAIPromptExecutionSettings = new()
        {
            FunctionChoiceBehavior = FunctionChoiceBehavior.Auto()
        };

        Console.WriteLine("Ask questions or give instructions to the copilot such as:\n" +
                          "- What time is it?\n" +
                          "- Turn on the porch light.\n" +
                          "- If it's before 7:00 pm, turn on the office light.\n" +
                          "- Which light is currently on?\n" +
                          "- Set an alarm for 6:00 am.\n");

        string sessionId = ResolveSessionId();
        ChatHistory chatHistory = await _chatHistoryStore.LoadAsync(sessionId, stoppingToken);
        EnsureSystemPrompt(chatHistory);
        if (chatHistory.Count > 0)
        {
            Console.WriteLine($"Loaded {chatHistory.Count} persisted messages for session '{sessionId}'.\n");
        }

        Console.Write("> ");

        string? input = null;
        while ((input = Console.ReadLine()) is not null)
        {
            Console.WriteLine();

            string context = await _ragSearchService.BuildContextAsync(input, stoppingToken);
            string enrichedInput = string.IsNullOrWhiteSpace(context)
                ? input
                : $"{context}\nUser request: {input}";

            chatHistory.AddUserMessage(enrichedInput);

                ChatMessageContent chatResult = await chatCompletionService.GetChatMessageContentAsync(chatHistory,
                    openAIPromptExecutionSettings, _kernel, stoppingToken);

            chatHistory.AddAssistantMessage(chatResult.Content ?? string.Empty);
            await _chatHistoryStore.SaveAsync(sessionId, chatHistory, stoppingToken);

            Console.Write($"\n>>> Result: {chatResult}\n\n> ");
        }

        _hostApplicationLifetime.StopApplication();
    }

    private void EnsureSystemPrompt(ChatHistory chatHistory)
    {
        bool hasSystem = chatHistory.Any(m => m.Role == AuthorRole.System);
        if (!hasSystem)
        {
            chatHistory.AddSystemMessage(_agentOptions.SystemPrompt);
        }

        // Trim in-context turns if needed (persisted history remains, but we keep window small for prompt)
        if (_agentOptions.MaxInContextTurns > 0)
        {
            int systemCount = chatHistory.Count(m => m.Role == AuthorRole.System);
            int allowed = systemCount + _agentOptions.MaxInContextTurns * 2; // user+assistant pairs
            if (chatHistory.Count > allowed)
            {
                int toRemove = chatHistory.Count - allowed;
                // Remove oldest non-system messages first
                var filtered = chatHistory.Where(m => m.Role == AuthorRole.System).ToList();
                var nonSystem = chatHistory.Where(m => m.Role != AuthorRole.System).Skip(toRemove).ToList();
                filtered.AddRange(nonSystem);

                chatHistory.Clear();
                foreach (var m in filtered)
                {
                    chatHistory.Add(m);
                }
            }
        }
    }

    private string ResolveSessionId()
    {
        string? envId = Environment.GetEnvironmentVariable(_memoryOptions.SessionIdEnvVar);
        if (!string.IsNullOrWhiteSpace(envId))
        {
            return envId.Trim();
        }

        return _memoryOptions.DefaultSessionId;
    }
}
