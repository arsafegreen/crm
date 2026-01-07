/*
 Copyright (c) Microsoft. All rights reserved.

 Example that demonstrates how to use Semantic Kernel in conjunction with dependency injection.

 Loads app configuration from:
 - appsettings.json.
 - appsettings.{Environment}.json.
 - Secret Manager when the app runs in the "Development" environment (set through the DOTNET_ENVIRONMENT variable).
 - Environment variables.
 - Command-line arguments.
*/

using HomeAutomation.Options;
using HomeAutomation.Plugins;
using HomeAutomation.Memory;
using HomeAutomation.Crm;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Options;
using Microsoft.SemanticKernel;
using Microsoft.SemanticKernel.ChatCompletion;
using Microsoft.SemanticKernel.Embeddings;
// For Azure OpenAI configuration
#pragma warning disable IDE0005 // Using directive is unnecessary.
using Microsoft.SemanticKernel.Connectors.OpenAI;

namespace HomeAutomation;

internal static class Program
{
    internal static async Task Main(string[] args)
    {
        bool ingestMode = args.Contains("--ingest", StringComparer.OrdinalIgnoreCase);
        int askIndex = Array.FindIndex(args, a => string.Equals(a, "--ask", StringComparison.OrdinalIgnoreCase));
        string? askQuestion = askIndex >= 0 && askIndex + 1 < args.Length ? args[askIndex + 1] : null;

        HostApplicationBuilder builder = Host.CreateApplicationBuilder(args);
        builder.Configuration.AddUserSecrets<Worker>();

        // Actual code to execute is found in Worker class
        builder.Services.AddHostedService<Worker>();

        // Get configuration
        builder.Services.AddOptions<OpenAIOptions>()
                        .Bind(builder.Configuration.GetSection(OpenAIOptions.SectionName))
                        .ValidateDataAnnotations()
                        .ValidateOnStart();

        builder.Services.AddOptions<MemoryOptions>()
                .Bind(builder.Configuration.GetSection(MemoryOptions.SectionName))
                .ValidateDataAnnotations()
                .ValidateOnStart();

        builder.Services.AddOptions<AgentOptions>()
                .Bind(builder.Configuration.GetSection(AgentOptions.SectionName))
                .ValidateDataAnnotations()
                .ValidateOnStart();

        builder.Services.AddOptions<EmbeddingOptions>()
                .Bind(builder.Configuration.GetSection(EmbeddingOptions.SectionName))
                .ValidateDataAnnotations()
                .ValidateOnStart();

        builder.Services.AddOptions<RagOptions>()
                .Bind(builder.Configuration.GetSection(RagOptions.SectionName))
                .ValidateDataAnnotations()
                .ValidateOnStart();

        /* Alternatively, you can use plain, Azure OpenAI after loading AzureOpenAIOptions instead  of OpenAI
        
        builder.Services.AddOptions<AzureOpenAIOptions>()
                        .Bind(builder.Configuration.GetSection(AzureOpenAIOptions.SectionName))
                        .ValidateDataAnnotations()
                        .ValidateOnStart();
        */

        // Chat completion service that kernels will use
        builder.Services.AddSingleton<IChatCompletionService>(sp =>
        {
            OpenAIOptions openAIOptions = sp.GetRequiredService<IOptions<OpenAIOptions>>().Value;

            // A custom HttpClient can be provided to this constructor
            return new OpenAIChatCompletionService(openAIOptions.ChatModelId, openAIOptions.ApiKey);

            /* Alternatively, you can use plain, Azure OpenAI after loading AzureOpenAIOptions instead
               of OpenAI options with builder.Services.AddOptions:
            
            AzureOpenAIOptions azureOpenAIOptions  = sp.GetRequiredService<IOptions<AzureOpenAIOptions>>().Value;
            return new AzureOpenAIChatCompletionService(azureOpenAIOptions.ChatDeploymentName, azureOpenAIOptions.Endpoint, azureOpenAIOptions.ApiKey);

            */
        });

        // Embeddings for RAG
        builder.Services.AddSingleton<ITextEmbeddingGenerationService>(sp =>
        {
            EmbeddingOptions embeddingOptions = sp.GetRequiredService<IOptions<EmbeddingOptions>>().Value;
            OpenAIOptions openAIOptions = sp.GetRequiredService<IOptions<OpenAIOptions>>().Value;
            return new OpenAITextEmbeddingGenerationService(embeddingOptions.ModelId, openAIOptions.ApiKey);
        });

        // Persisted, encrypted chat history store
        builder.Services.AddSingleton<IChatHistoryStore, EncryptedFileChatHistoryStore>();

        // Add plugins that can be used by kernels
        // The plugins are added as singletons so that they can be used by multiple kernels
        builder.Services.AddSingleton<MyTimePlugin>();
        builder.Services.AddSingleton<MyAlarmPlugin>();
        builder.Services.AddKeyedSingleton<MyLightPlugin>("OfficeLight");
        builder.Services.AddKeyedSingleton<MyLightPlugin>("PorchLight", (sp, key) =>
        {
            return new MyLightPlugin(turnedOn: true);
        });
        builder.Services.AddSingleton<ICrmRepository, InMemoryCrmRepository>();
        builder.Services.AddSingleton<CrmPlugin>();

        // RAG store and services
        builder.Services.AddSingleton<FileEmbeddingStore>(sp =>
        {
            RagOptions ragOptions = sp.GetRequiredService<IOptions<RagOptions>>().Value;
            var logger = sp.GetRequiredService<ILogger<FileEmbeddingStore>>();
            return new FileEmbeddingStore(ragOptions.IndexFile, logger);
        });
        builder.Services.AddSingleton<RagIngestionService>();
        builder.Services.AddSingleton<RagSearchService>();

        /* To add an OpenAI or OpenAPI plugin, you need to be using Microsoft.SemanticKernel.Plugins.OpenApi.
           Then create a temporary kernel, use it to load the plugin and add it as keyed singleton.
        Kernel kernel = new();
        KernelPlugin openAIPlugin = await kernel.ImportPluginFromOpenAIAsync("<plugin name>", new Uri("<OpenAI-plugin>"));
        builder.Services.AddKeyedSingleton<KernelPlugin>("MyImportedOpenAIPlugin", openAIPlugin);

        KernelPlugin openApiPlugin = await kernel.ImportPluginFromOpenApiAsync("<plugin name>", new Uri("<OpenAPI-plugin>"));
        builder.Services.AddKeyedSingleton<KernelPlugin>("MyImportedOpenApiPlugin", openApiPlugin);*/

        // Add a home automation kernel to the dependency injection container
        builder.Services.AddKeyedTransient<Kernel>("HomeAutomationKernel", (sp, key) =>
        {
            // Create a collection of plugins that the kernel will use
            KernelPluginCollection pluginCollection = [];
            pluginCollection.AddFromObject(sp.GetRequiredService<MyTimePlugin>());
            pluginCollection.AddFromObject(sp.GetRequiredService<MyAlarmPlugin>());
            pluginCollection.AddFromObject(sp.GetRequiredKeyedService<MyLightPlugin>("OfficeLight"), "OfficeLight");
            pluginCollection.AddFromObject(sp.GetRequiredKeyedService<MyLightPlugin>("PorchLight"), "PorchLight");
            pluginCollection.AddFromObject(sp.GetRequiredService<CrmPlugin>(), "CRM");

            // When created by the dependency injection container, Semantic Kernel logging is included by default
            return new Kernel(sp, pluginCollection);
        });

        using IHost host = builder.Build();

        if (ingestMode)
        {
            var ingestion = host.Services.GetRequiredService<RagIngestionService>();
            await ingestion.IngestAsync(CancellationToken.None);
            return;
        }

        if (!string.IsNullOrWhiteSpace(askQuestion))
        {
            using IServiceScope scope = host.Services.CreateScope();
            await RunSingleTurnAsync(scope.ServiceProvider, askQuestion, CancellationToken.None);
            return;
        }

        // Optional ingestion mode: dotnet run --project HomeAutomation.csproj -- --ingest
        await host.RunAsync();
    }

    private static async Task RunSingleTurnAsync(IServiceProvider services, string question, CancellationToken cancellationToken)
    {
        var kernel = services.GetRequiredKeyedService<Kernel>("HomeAutomationKernel");
        var historyStore = services.GetRequiredService<IChatHistoryStore>();
        var memoryOptions = services.GetRequiredService<IOptions<MemoryOptions>>().Value;
        var agentOptions = services.GetRequiredService<IOptions<AgentOptions>>().Value;
        var ragSearch = services.GetRequiredService<RagSearchService>();
        var chatService = kernel.GetRequiredService<IChatCompletionService>();

        string sessionId = ResolveSessionId(memoryOptions);
        ChatHistory chatHistory = await historyStore.LoadAsync(sessionId, cancellationToken).ConfigureAwait(false);
        EnsureSystemPrompt(chatHistory, agentOptions);

        string context = await ragSearch.BuildContextAsync(question, cancellationToken).ConfigureAwait(false);
        string enriched = string.IsNullOrWhiteSpace(context) ? question : $"{context}\nUser request: {question}";

        chatHistory.AddUserMessage(enriched);

        OpenAIPromptExecutionSettings settings = new()
        {
            FunctionChoiceBehavior = FunctionChoiceBehavior.Auto()
        };

        ChatMessageContent result = await chatService.GetChatMessageContentAsync(chatHistory, settings, kernel, cancellationToken).ConfigureAwait(false);

        chatHistory.AddAssistantMessage(result.Content ?? string.Empty);
        await historyStore.SaveAsync(sessionId, chatHistory, cancellationToken).ConfigureAwait(false);

        Console.WriteLine(result.Content);
    }

    private static void EnsureSystemPrompt(ChatHistory chatHistory, AgentOptions agentOptions)
    {
        bool hasSystem = chatHistory.Any(m => m.Role == AuthorRole.System);
        if (!hasSystem)
        {
            chatHistory.AddSystemMessage(agentOptions.SystemPrompt);
        }

        if (agentOptions.MaxInContextTurns > 0)
        {
            int systemCount = chatHistory.Count(m => m.Role == AuthorRole.System);
            int allowed = systemCount + agentOptions.MaxInContextTurns * 2;
            if (chatHistory.Count > allowed)
            {
                int toRemove = chatHistory.Count - allowed;
                var filtered = chatHistory.Where(m => m.Role == AuthorRole.System).ToList();
                var nonSystem = chatHistory.Where(m => m.Role != AuthorRole.System).Skip(toRemove).ToList();
                chatHistory.Clear();
                foreach (var m in filtered)
                {
                    chatHistory.Add(m);
                }
                foreach (var m in nonSystem)
                {
                    chatHistory.Add(m);
                }
            }
        }
    }

    private static string ResolveSessionId(MemoryOptions options)
    {
        string? envId = Environment.GetEnvironmentVariable(options.SessionIdEnvVar);
        if (!string.IsNullOrWhiteSpace(envId))
        {
            return envId.Trim();
        }

        return options.DefaultSessionId;
    }
}
