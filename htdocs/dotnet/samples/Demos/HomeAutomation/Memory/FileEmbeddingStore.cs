// Copyright (c) Microsoft. All rights reserved.

using System.Text.Json;
using Microsoft.Extensions.Logging;

namespace HomeAutomation.Memory;

/// <summary>
/// Simple file-based embedding store with cosine similarity search.
/// Not optimized for very large corpora but sufficient for local RAG.
/// </summary>
public sealed class FileEmbeddingStore
{
    private readonly string _indexPath;
    private readonly ILogger<FileEmbeddingStore> _logger;
    private readonly List<Record> _records;

    public FileEmbeddingStore(string indexPath, ILogger<FileEmbeddingStore> logger)
    {
        _indexPath = Path.GetFullPath(indexPath);
        _logger = logger;
        _records = Load();
    }

    public IReadOnlyList<Record> Records => _records;

    public void Upsert(Record record)
    {
        int idx = _records.FindIndex(r => r.Id == record.Id);
        if (idx >= 0)
        {
            _records[idx] = record;
        }
        else
        {
            _records.Add(record);
        }
    }

    public void Save()
    {
        Directory.CreateDirectory(Path.GetDirectoryName(_indexPath)!);
        var options = new JsonSerializerOptions { WriteIndented = false };
        string json = JsonSerializer.Serialize(_records, options);
        File.WriteAllText(_indexPath, json);
        _logger.LogInformation("Saved embedding index with {Count} records to {Path}.", _records.Count, _indexPath);
    }

    public IReadOnlyList<Record> Search(float[] queryEmbedding, int topK)
    {
        if (_records.Count == 0)
        {
            return Array.Empty<Record>();
        }

        var scored = new List<(Record record, double score)>(_records.Count);
        foreach (Record r in _records)
        {
            double score = CosineSimilarity(queryEmbedding, r.Embedding);
            scored.Add((r, score));
        }

        return scored
            .OrderByDescending(s => s.score)
            .Take(topK)
            .Select(s => s.record)
            .ToArray();
    }

    private List<Record> Load()
    {
        if (!File.Exists(_indexPath))
        {
            _logger.LogInformation("No embedding index found at {Path}; starting empty.", _indexPath);
            return new List<Record>();
        }

        try
        {
            string json = File.ReadAllText(_indexPath);
            var records = JsonSerializer.Deserialize<List<Record>>(json);
            return records ?? new List<Record>();
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Failed to load embedding index at {Path}; starting empty.", _indexPath);
            return new List<Record>();
        }
    }

    private static double CosineSimilarity(float[] a, float[] b)
    {
        if (a.Length != b.Length)
        {
            return -1;
        }

        double dot = 0;
        double normA = 0;
        double normB = 0;

        for (int i = 0; i < a.Length; i++)
        {
            dot += a[i] * b[i];
            normA += a[i] * a[i];
            normB += b[i] * b[i];
        }

        double denom = Math.Sqrt(normA) * Math.Sqrt(normB);
        return denom == 0 ? -1 : dot / denom;
    }

    public sealed class Record
    {
        public string Id { get; set; } = string.Empty;
        public string FilePath { get; set; } = string.Empty;
        public string Content { get; set; } = string.Empty;
        public float[] Embedding { get; set; } = Array.Empty<float>();
    }
}
