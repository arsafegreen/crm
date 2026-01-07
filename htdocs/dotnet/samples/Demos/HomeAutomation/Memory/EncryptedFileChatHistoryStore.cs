// Copyright (c) Microsoft. All rights reserved.

using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using HomeAutomation.Options;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Microsoft.SemanticKernel.ChatCompletion;

namespace HomeAutomation.Memory;

/// <summary>
/// Stores chat history on disk with AES-GCM encryption using a key provided via environment variable.
/// </summary>
public sealed class EncryptedFileChatHistoryStore : IChatHistoryStore
{
    private readonly string _dataDirectory;
    private readonly byte[] _encryptionKey;
    private readonly string _defaultSessionId;
    private readonly ILogger<EncryptedFileChatHistoryStore> _logger;

    public EncryptedFileChatHistoryStore(IOptions<MemoryOptions> options, ILogger<EncryptedFileChatHistoryStore> logger)
    {
        _logger = logger;
        MemoryOptions memoryOptions = options.Value;

        _dataDirectory = Path.GetFullPath(memoryOptions.DataDirectory);
        Directory.CreateDirectory(_dataDirectory);

        _defaultSessionId = memoryOptions.DefaultSessionId;

        string? keyBase64 = Environment.GetEnvironmentVariable(memoryOptions.EncryptionKeyEnvVar);
        if (string.IsNullOrWhiteSpace(keyBase64))
        {
            throw new InvalidOperationException($"Encryption key not found. Set environment variable '{memoryOptions.EncryptionKeyEnvVar}' with a base64 256-bit key.");
        }

        _encryptionKey = Convert.FromBase64String(keyBase64.Trim());
        if (_encryptionKey.Length != 32)
        {
            throw new InvalidOperationException("Encryption key must be 256 bits (32 bytes).");
        }
    }

    public async Task<ChatHistory> LoadAsync(string sessionId, CancellationToken cancellationToken)
    {
        string path = GetSessionPath(sessionId);
        if (!File.Exists(path))
        {
            _logger.LogInformation("No existing history for session {SessionId}.", sessionId);
            return new ChatHistory();
        }

        byte[] encrypted = await File.ReadAllBytesAsync(path, cancellationToken).ConfigureAwait(false);
        byte[] plaintext = Decrypt(encrypted);

        ChatMessageRecord[]? records = JsonSerializer.Deserialize<ChatMessageRecord[]>(plaintext);
        ChatHistory history = new();

        if (records is null)
        {
            _logger.LogWarning("History file for session {SessionId} is empty or invalid.", sessionId);
            return history;
        }

        foreach (ChatMessageRecord record in records)
        {
            if (string.IsNullOrWhiteSpace(record.Role) || record.Content is null)
            {
                continue;
            }

            history.AddMessage(Enum.Parse<AuthorRole>(record.Role, ignoreCase: true), record.Content);
        }

        _logger.LogInformation("Loaded {Count} messages for session {SessionId}.", history.Count, sessionId);
        return history;
    }

    public async Task SaveAsync(string sessionId, ChatHistory history, CancellationToken cancellationToken)
    {
        string path = GetSessionPath(sessionId);

        ChatMessageRecord[] records = history.Select(m => new ChatMessageRecord
        {
            Role = m.Role.Label,
            Content = m.Content ?? string.Empty
        }).ToArray();

        byte[] plaintext = JsonSerializer.SerializeToUtf8Bytes(records, new JsonSerializerOptions
        {
            WriteIndented = false
        });

        byte[] encrypted = Encrypt(plaintext);
        await File.WriteAllBytesAsync(path, encrypted, cancellationToken).ConfigureAwait(false);
        _logger.LogInformation("Persisted {Count} messages for session {SessionId}.", records.Length, sessionId);
    }

    private string GetSessionPath(string sessionId)
    {
        string effectiveId = string.IsNullOrWhiteSpace(sessionId) ? _defaultSessionId : sessionId;
        string safeName = effectiveId.Replace(':', '-').Replace('/', '-').Replace('\\', '-');
        return Path.Combine(_dataDirectory, safeName + ".json.enc");
    }

    private byte[] Encrypt(ReadOnlySpan<byte> plaintext)
    {
        byte[] nonce = RandomNumberGenerator.GetBytes(12); // 96-bit nonce for AES-GCM
        byte[] ciphertext = new byte[plaintext.Length];
        byte[] tag = new byte[16];

        using var aesGcm = new AesGcm(_encryptionKey);
        aesGcm.Encrypt(nonce, plaintext, ciphertext, tag);

        byte[] output = new byte[nonce.Length + tag.Length + ciphertext.Length];
        Buffer.BlockCopy(nonce, 0, output, 0, nonce.Length);
        Buffer.BlockCopy(tag, 0, output, nonce.Length, tag.Length);
        Buffer.BlockCopy(ciphertext, 0, output, nonce.Length + tag.Length, ciphertext.Length);
        return output;
    }

    private byte[] Decrypt(ReadOnlySpan<byte> encrypted)
    {
        if (encrypted.Length < 12 + 16)
        {
            throw new InvalidOperationException("Encrypted payload too small.");
        }

        ReadOnlySpan<byte> nonce = encrypted[..12];
        ReadOnlySpan<byte> tag = encrypted.Slice(12, 16);
        ReadOnlySpan<byte> ciphertext = encrypted[28..];

        byte[] plaintext = new byte[ciphertext.Length];
        using var aesGcm = new AesGcm(_encryptionKey);
        aesGcm.Decrypt(nonce, ciphertext, tag, plaintext);
        return plaintext;
    }

    private sealed record ChatMessageRecord
    {
        public string? Role { get; init; }
        public string? Content { get; init; }
    }
}
