// Copyright (c) Microsoft. All rights reserved.

using Microsoft.SemanticKernel.ChatCompletion;

namespace HomeAutomation.Memory;

/// <summary>
/// Abstraction for persisting and loading chat histories with optional encryption.
/// </summary>
public interface IChatHistoryStore
{
    Task<ChatHistory> LoadAsync(string sessionId, CancellationToken cancellationToken);

    Task SaveAsync(string sessionId, ChatHistory history, CancellationToken cancellationToken);
}
