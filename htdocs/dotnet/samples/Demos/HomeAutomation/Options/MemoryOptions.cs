// Copyright (c) Microsoft. All rights reserved.

using System.ComponentModel.DataAnnotations;

namespace HomeAutomation.Options;

/// <summary>
/// Options to configure persisted chat memory.
/// </summary>
public sealed class MemoryOptions
{
    public const string SectionName = "Memory";

    [Required]
    public string DataDirectory { get; set; } = "memory";

    /// <summary>
    /// Name of the environment variable that contains the base64-encoded 256-bit encryption key.
    /// </summary>
    [Required]
    public string EncryptionKeyEnvVar { get; set; } = "MEMORY_ENCRYPTION_KEY";

    /// <summary>
    /// Name of the environment variable that optionally overrides the session identifier.
    /// </summary>
    public string SessionIdEnvVar { get; set; } = "MEMORY_SESSION_ID";

    /// <summary>
    /// Optional logical session identifier for the console chat loop.
    /// </summary>
    public string DefaultSessionId { get; set; } = "console";
}
