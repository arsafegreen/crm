# "House Automation" example illustrating how to use Semantic Kernel with dependency injection

This example demonstrates a few dependency injection patterns that can be used with Semantic Kernel.


## Configuring Secrets

The example require credentials to access OpenAI or Azure OpenAI.

If you have set up those credentials as secrets within Secret Manager or through environment variables for other samples from the solution in which this project is found, they will be re-used.

### To set your secrets with Secret Manager:

```
cd dotnet/samples/Demos/HouseAutomation

dotnet user-secrets init

dotnet user-secrets set "OpenAI:ChatModelId" "..."
dotnet user-secrets set "OpenAI:ApiKey" "..."

dotnet user-secrets set "AzureOpenAI:ChatDeploymentName" "..."
dotnet user-secrets set "AzureOpenAI:Endpoint" "https://... .openai.azure.com/"
dotnet user-secrets set "AzureOpenAI:ApiKey" "..."
```

### To set your secrets with environment variables

Use these names:

```
# OpenAI
OpenAI__ChatModelId
OpenAI__ApiKey

# Azure OpenAI
AzureOpenAI__ChatDeploymentName
AzureOpenAI__Endpoint
AzureOpenAI__ApiKey

## Agent, memory, RAG

- Persona and in-context window: configure in `Agent` section of appsettings.
- Memory: persisted encrypted history uses `MEMORY_ENCRYPTION_KEY` (base64 256 bits). Override session id with `MEMORY_SESSION_ID`.
- CRM plugin: by default uses an in-memory repository; swap `ICrmRepository` with your CRM client implementation in DI.
- RAG (local repo context): configure `Embedding` and `Rag` sections. Ingest files with `dotnet run --project HomeAutomation.csproj -- --ingest` (uses `Rag:SourceDirectory`, builds `Rag:IndexFile`).

## Quick commands

- Ingest embeddings: `dotnet run --project HomeAutomation.csproj -- --ingest`
- Ask one vez sem abrir loop interativo: `dotnet run --project HomeAutomation.csproj -- --ask "sua pergunta"`
- Loop interativo com memória+RAG: `dotnet run --project HomeAutomation.csproj`
```
