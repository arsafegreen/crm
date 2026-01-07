// Copyright (c) Microsoft. All rights reserved.

using System.Text;
using HomeAutomation.Options;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.SemanticKernel.Embeddings;

namespace HomeAutomation.Memory;

/// <summary>
/// Retrieves relevant chunks from the local embedding index and returns formatted context.
/// </summary>
public sealed class RagSearchService
{
    private readonly RagOptions _options;
    private readonly FileEmbeddingStore _store;
    private readonly ITextEmbeddingGenerationService _embeddingService;
    private readonly ILogger<RagSearchService> _logger;

    public RagSearchService(IOptions<RagOptions> options,
                            FileEmbeddingStore store,
                            ITextEmbeddingGenerationService embeddingService,
                            ILogger<RagSearchService> logger)
    {
        _options = options.Value;
        _store = store;
        _embeddingService = embeddingService;
        _logger = logger;
    }

    public async Task<string> BuildContextAsync(string query, CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(query))
        {
            return string.Empty;
        }

        if (_store.Records.Count == 0)
        {
            _logger.LogWarning("RAG search requested but index is empty. Run ingestion.");
            return string.Empty;
        }

        var embedding = await _embeddingService.GenerateEmbeddingAsync(query, cancellationToken).ConfigureAwait(false);
        var matches = _store.Search(embedding.ToArray(), _options.TopK);

        if (matches.Count == 0)
        {
            return string.Empty;
        }

        StringBuilder sb = new();
        sb.AppendLine("Relevant context from repository:");
        foreach (var match in matches)
        {
            sb.AppendLine($"[File: {match.FilePath}]");
            sb.AppendLine(match.Content.Trim());
            sb.AppendLine();
        }

        return sb.ToString();
    }
}
