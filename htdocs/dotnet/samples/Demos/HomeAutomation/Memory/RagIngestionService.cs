// Copyright (c) Microsoft. All rights reserved.

using System.Collections.Concurrent;
using System.Text;
using HomeAutomation.Options;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.SemanticKernel.Embeddings;

namespace HomeAutomation.Memory;

/// <summary>
/// Ingests text files into a local embedding index for retrieval.
/// </summary>
public sealed class RagIngestionService
{
    private static readonly string[] _defaultExtensions = [".cs", ".json", ".md", ".txt", ".php", ".js", ".ts", ".html", ".css", ".xml", ".yml", ".yaml", ".ini", ".cfg", ".env"];

    private readonly RagOptions _options;
    private readonly FileEmbeddingStore _store;
    private readonly ITextEmbeddingGenerationService _embeddingService;
    private readonly ILogger<RagIngestionService> _logger;

    public RagIngestionService(IOptions<RagOptions> options,
                               FileEmbeddingStore store,
                               ITextEmbeddingGenerationService embeddingService,
                               ILogger<RagIngestionService> logger)
    {
        _options = options.Value;
        _store = store;
        _embeddingService = embeddingService;
        _logger = logger;
    }

    public async Task IngestAsync(CancellationToken cancellationToken)
    {
        string root = Path.GetFullPath(_options.SourceDirectory);
        if (!Directory.Exists(root))
        {
            throw new DirectoryNotFoundException($"Source directory not found: {root}");
        }

        var files = Directory.EnumerateFiles(root, "*", SearchOption.AllDirectories)
            .Where(f => _defaultExtensions.Contains(Path.GetExtension(f), StringComparer.OrdinalIgnoreCase))
            .ToArray();

        _logger.LogInformation("Ingesting {Count} files from {Root}.", files.Length, root);

        ConcurrentBag<FileEmbeddingStore.Record> newRecords = new();

        foreach (string file in files)
        {
            cancellationToken.ThrowIfCancellationRequested();
            string text = await File.ReadAllTextAsync(file, Encoding.UTF8, cancellationToken).ConfigureAwait(false);
            foreach (var chunk in ChunkText(text, _options.ChunkSize, _options.ChunkOverlap))
            {
                string id = $"{file}#{chunk.Index}";
                var embedding = await _embeddingService.GenerateEmbeddingAsync(chunk.Content, cancellationToken).ConfigureAwait(false);
                newRecords.Add(new FileEmbeddingStore.Record
                {
                    Id = id,
                    FilePath = Path.GetRelativePath(root, file),
                    Content = chunk.Content,
                    Embedding = embedding.ToArray()
                });
            }
        }

        foreach (var record in newRecords)
        {
            _store.Upsert(record);
        }

        _store.Save();
        _logger.LogInformation("Ingestion completed. Index size: {Count}", _store.Records.Count);
    }

    private static IEnumerable<(int Index, string Content)> ChunkText(string text, int chunkSize, int overlap)
    {
        int length = text.Length;
        int start = 0;
        int index = 0;
        while (start < length)
        {
            int size = Math.Min(chunkSize, length - start);
            string chunk = text.Substring(start, size);
            yield return (index, chunk);
            start += Math.Max(1, chunkSize - overlap);
            index++;
        }
    }
}
