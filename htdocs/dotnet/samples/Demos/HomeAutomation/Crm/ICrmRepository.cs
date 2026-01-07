// Copyright (c) Microsoft. All rights reserved.

using HomeAutomation.Plugins;

namespace HomeAutomation.Crm;

/// <summary>
/// Abstraction over CRM data access; replace with real implementation (API, DB).
/// </summary>
public interface ICrmRepository
{
    CrmPlugin.Customer? GetCustomer(string customerId);

    IReadOnlyList<CrmPlugin.Opportunity> ListOpportunities(string customerId);

    CrmPlugin.Opportunity UpsertOpportunity(string id, string customerId, string title, decimal probability, string stage);

    CrmPlugin.Note AddNote(string opportunityId, string content);
}
