# Checkpoints diários

Para registrar o progresso geral do projeto sem perder contexto:

## Como gerar o checkpoint do dia

1. No final do expediente, rode:

```powershell
python scripts/create_checkpoint.py --module "Nome do foco" --existing "Resumo do que já existe" --notes "O que foi feito" --pending "Próximos passos"
```

Campos úteis:

- `--module`: tema principal que tocamos (ex.: "Marketing Email", "CRM Pipeline").
- `--existing`: lembretes do que já está implementado e deve ser reutilizado/ajustado (evita criar duplicatas).
- `--notes`: resumo das entregas ou descobertas do dia.
- `--pending`: próximos passos para retomar amanhã ou riscos em aberto.
- `--date` e `--log-file` são opcionais (padrão: data atual e `docs/checkpoints/daily.md`).

Cada execução **acrescenta** uma nova seção em `docs/checkpoints/daily.md`, mantendo o histórico centralizado.

## Dicas de uso

- Se trabalhar em vários tópicos no mesmo dia, rode o comando mais de uma vez com módulos diferentes.
- Antes de iniciar uma tarefa nova, leia a última entrada do arquivo para confirmar o que já existe.
- Use o checkpoint junto com o TODO list para saber o estado geral + itens específicos.
- Caso o projeto esteja dentro de um repositório Git, o script incluirá branch, commit e um resumo do `git status` automaticamente.
