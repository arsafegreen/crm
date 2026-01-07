// Copyright (c) Microsoft. All rights reserved.

using System.ComponentModel.DataAnnotations;

namespace HomeAutomation.Options;

/// <summary>
/// Embedding model configuration for RAG.
/// </summary>
public sealed class EmbeddingOptions
{
    public const string SectionName = "Embedding";

    [Required]
    public string ModelId { get; set; } = "text-embedding-3-small";
}
