# Configuração do Weather Underground

Configure a chave no ambiente da aplicação. Não armazene a chave real no repositório:

```dotenv
WUNDERGROUND_PUBLIC_KEY=<sua-chave>
WUNDERGROUND_API_URL=https://api.weather.com/v2/pws/observations/current
```

O Laravel carrega esses valores por `config/services.php`; a coleta usa
`services.wunderground.key` e `.url`. Para uma estação PWS, use `provider` igual
a `wunderground` e informe o station ID no campo `loggers`. O endpoint retorna
temperatura em Celsius e, quando disponível, irradiação solar em W/m².

As regras de classificação usam intervalo de 30 minutos, tolerância temporal de
meio passo e quatro amostras mínimas para avaliar o desvio da média.

Se o endpoint retornar 401 ou 403, a coleta usa Open-Meteo como fallback com as
coordenadas da estação. O payload mantém `_source_provider: open_meteo` para que
as leituras usem o parser e o intervalo de classificação de 15 minutos desse
provedor; o uso do fallback também é registrado no log.
