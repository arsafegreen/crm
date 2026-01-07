// Copyright (c) Microsoft. All rights reserved.

using System.ComponentModel.DataAnnotations;

namespace HomeAutomation.Options;

/// <summary>
/// Retrieval-augmented generation settings for local code/doc search.
/// </summary>
public sealed class RagOptions
{
    public const string SectionName = "Rag";

    /// <summary>
    /// Root directory to ingest (e.g., htdocs).
    /// </summary>
    [Required]
    public string SourceDirectory { get; set; } = "htdocs";

    /// <summary>
    /// Path to the persisted embedding index file.
    /// </summary>
    [Required]
    public string IndexFile { get; set; } = "memory/index.json";

    /// <summary>
    /// Max number of top results to inject as context.
    /// </summary>
    [Range(1, 20)]
    public int TopK { get; set; } = 4;

    /// <summary>
    /// Max characters per chunk when ingesting.
    /// </summary>
    [Range(200, 4000)]
    public int ChunkSize { get; set; } = 1200;

    /// <summary>
    /// Step overlap between chunks.
    /// </summary>
    [Range(0, 1000)]
    public int ChunkOverlap { get; set; } = 200;
}
