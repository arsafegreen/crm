// Copyright (c) Microsoft. All rights reserved.

using System.ComponentModel;
using HomeAutomation.Crm;
using Microsoft.SemanticKernel;

namespace HomeAutomation.Plugins;

/// <summary>
/// Minimal CRM-facing plugin demonstrating professional function contracts. Replace storage with real CRM API.
/// </summary>
[Description("CRM operations: customers, opportunities, notes.")]
public sealed class CrmPlugin(ICrmRepository repository)
{
    private readonly ICrmRepository _repository = repository;

    [KernelFunction, Description("Buscar dados do cliente por id curto (slug). Retorna nome e email.")]
    public Customer? GetCustomer(string customerId)
    {
        return _repository.GetCustomer(customerId);
    }

    [KernelFunction, Description("Listar oportunidades de um cliente pelo id curto (slug).")]
    public Opportunity[] ListOpportunities(string customerId)
    {
        return _repository.ListOpportunities(customerId).ToArray();
    }

    [KernelFunction, Description("Criar ou atualizar oportunidade. Requer id, customerId, titulo, probabilidade (0-1) e estágio.")]
    public Opportunity UpsertOpportunity(string id, string customerId, string title, decimal probability, string stage)
    {
        return _repository.UpsertOpportunity(id, customerId, title, probability, stage);
    }

    [KernelFunction, Description("Registrar nota/atividade em uma oportunidade.")]
    public Note AddNote(string opportunityId, string content)
    {
        return _repository.AddNote(opportunityId, content);
    }

    public sealed class Customer
    {
        public Customer(string id, string name, string email)
        {
            Id = id;
            Name = name;
            Email = email;
        }

        public string Id { get; }
        public string Name { get; }
        public string Email { get; }
    }

    public sealed class Opportunity
    {
        public Opportunity(string id, string customerId, string title, decimal probability, string stage)
        {
            Id = id;
            CustomerId = customerId;
            Title = title;
            Probability = probability;
            Stage = stage;
        }

        public string Id { get; }
        public string CustomerId { get; set; }
        public string Title { get; set; }
        public decimal Probability { get; set; }
        public string Stage { get; set; }
    }

    public sealed class Note
    {
        public Note(string id, string opportunityId, DateTimeOffset createdAt, string content)
        {
            Id = id;
            OpportunityId = opportunityId;
            CreatedAt = createdAt;
            Content = content;
        }

        public string Id { get; }
        public string OpportunityId { get; }
        public DateTimeOffset CreatedAt { get; }
        public string Content { get; }
    }
}
