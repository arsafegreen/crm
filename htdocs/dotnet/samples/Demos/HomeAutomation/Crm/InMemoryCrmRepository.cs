// Copyright (c) Microsoft. All rights reserved.

using HomeAutomation.Plugins;

namespace HomeAutomation.Crm;

/// <summary>
/// Default in-memory repository. Swap for a real CRM repository without touching plugins.
/// </summary>
public sealed class InMemoryCrmRepository : ICrmRepository
{
    private readonly Dictionary<string, CrmPlugin.Customer> _customers = new(StringComparer.OrdinalIgnoreCase)
    {
        ["contoso"] = new CrmPlugin.Customer("contoso", "Contoso Ltd.", "contato@contoso.com"),
        ["fabrikam"] = new CrmPlugin.Customer("fabrikam", "Fabrikam Inc.", "vendas@fabrikam.com"),
    };

    private readonly List<CrmPlugin.Opportunity> _opportunities =
    [
        new CrmPlugin.Opportunity("OPP-1001", "contoso", "Upgrade suite", 0.65m, "Discovery"),
        new CrmPlugin.Opportunity("OPP-1002", "fabrikam", "Renovação contrato", 0.4m, "Proposal"),
    ];

    public CrmPlugin.Customer? GetCustomer(string customerId)
    {
        _customers.TryGetValue(customerId, out CrmPlugin.Customer? customer);
        return customer;
    }

    public IReadOnlyList<CrmPlugin.Opportunity> ListOpportunities(string customerId)
    {
        return _opportunities.Where(o => string.Equals(o.CustomerId, customerId, StringComparison.OrdinalIgnoreCase)).ToArray();
    }

    public CrmPlugin.Opportunity UpsertOpportunity(string id, string customerId, string title, decimal probability, string stage)
    {
        CrmPlugin.Opportunity? existing = _opportunities.FirstOrDefault(o => string.Equals(o.Id, id, StringComparison.OrdinalIgnoreCase));
        if (existing is null)
        {
            var opp = new CrmPlugin.Opportunity(id, customerId, title, probability, stage);
            _opportunities.Add(opp);
            return opp;
        }

        existing.Title = title;
        existing.Probability = probability;
        existing.Stage = stage;
        existing.CustomerId = customerId;
        return existing;
    }

    public CrmPlugin.Note AddNote(string opportunityId, string content)
    {
        return new CrmPlugin.Note(Guid.NewGuid().ToString("N"), opportunityId, DateTimeOffset.UtcNow, content);
    }
}
