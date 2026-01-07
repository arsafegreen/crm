// Copyright (c) Microsoft. All rights reserved.

using System.ComponentModel.DataAnnotations;

namespace HomeAutomation.Options;

/// <summary>
/// Agent persona and behavior tuning.
/// </summary>
public sealed class AgentOptions
{
    public const string SectionName = "Agent";

    [Required]
    public string SystemPrompt { get; set; } =
        "You are a professional CRM copilot. Always use available functions for data. " +
        "Ask for confirmation before mutating customer data. Be concise, list steps, " +
        "and maintain context from memory.";

    /// <summary>
    /// Maximum number of turns to retain in short-term context; older turns remain persisted.
    /// </summary>
    public int MaxInContextTurns { get; set; } = 20;
}
