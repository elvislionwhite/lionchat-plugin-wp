# LionChat Lead Integrator - WordPress Plugin

Plugin nativo para capturar leads de formularios WordPress e Elementor, enviar automaticamente para o LionChat com tracking completo de campanhas.

## O que faz

- Captura leads de formularios **Elementor Pro**, **Contact Form 7** e **WPForms**
- Cria contatos automaticamente no LionChat via API
- Envia mensagens automaticas pelo WhatsApp (respostas prontas ou templates Cloud API)
- Aplica tags e dispara automacoes
- **Tracking de campanhas**: captura UTMs, Google Click ID, Meta Click ID da URL e salva na conversa

## Tracking de Campanhas (v2.5)

O plugin captura automaticamente parametros de campanha da URL do visitante e salva como atributos da conversa no LionChat.

### Como funciona

```
1. Visitante chega no site via anuncio
   URL: site.com/produto?utm_source=google&utm_medium=cpc&gclid=CjwKCA...

2. Plugin salva os parametros em cookie (persistente 30 dias)

3. Visitante navega pelo site (cookie acompanha)

4. Visitante preenche formulario

5. Plugin envia lead + dados de campanha para o LionChat
   → Conversa criada ja com os dados de origem
   → Ou conversa existente atualizada (merge, sem apagar dados anteriores)
```

### Parametros capturados

| Parametro | Origem | Exemplo |
|-----------|--------|---------|
| `utm_source` | Qualquer campanha | google, facebook, instagram |
| `utm_medium` | Qualquer campanha | cpc, email, social, organic |
| `utm_campaign` | Qualquer campanha | black-friday, lancamento-v2 |
| `utm_term` | Google Ads | sapato preto masculino |
| `utm_content` | Variacao de anuncio | banner-v2, cta-verde |
| `gclid` | Google Ads | CjwKCAjw... |
| `gbraid` | Google Ads (iOS) | abc123... |
| `wbraid` | Google Ads (Web) | xyz789... |
| `fbclid` | Meta Ads (link) | IwAR3x... |
| `ctwa_clid` | Meta Click-to-WhatsApp | abc123 |
| `ctwa_source_id` | Meta CTWA | 12345678 |
| `ctwa_source_url` | Meta CTWA | https://fb.com/ads/... |
| `ctwa_source_type` | Meta CTWA | ad, post |

### Comportamento do cookie

- **Persistente por 30 dias** desde a ultima visita (renova a cada acesso ao site)
- **Funciona entre paginas**: visitante pode entrar numa pagina e converter em outra
- **Merge inteligente**: se o visitante voltar com UTMs novos, os novos substituem os mesmos campos e os diferentes sao mantidos
- **Seguro**: flag `Secure` em sites HTTPS, valores sanitizados

### Onde os dados ficam no LionChat

Os dados de campanha sao salvos como `custom_attributes` da **conversa**:
- Conversa nova: criada ja com os dados
- Conversa existente: merge com dados anteriores (preserva dados CTWA do WhatsApp)

Isso permite:
- Ver de onde o lead veio direto na conversa
- Cards do Kanban herdam automaticamente os dados de tracking da conversa
- Futuros relatorios de vendas por origem de trafego

## Instalacao

1. Baixe o plugin: [Download](https://github.com/elvislionwhite/lionchat-plugin-wp/releases/latest/download/lionchat-lead-integrator.zip)
2. No WordPress: **Plugins > Adicionar Novo > Enviar Plugin**
3. Selecione o arquivo `.zip` e clique em **Instalar**
4. **Ative** o plugin

## Configuracao

1. No menu lateral do WordPress, clique em **LionChat**
2. Preencha:
   - **URL da instancia**: `https://app.lionchat.com.br` (ja vem preenchido)
   - **Token de Acesso**: encontrado em Configuracoes > Conta > Token de Acesso
   - **ID da Conta**: numero da sua conta
3. Clique em **Testar Conexao**
4. Escolha a **Caixa de entrada** para notificacoes (opcional)
5. Escolha a **Caixa WhatsApp** para envio automatico (opcional)

## Regras de Formulario

Para cada formulario do site, voce pode configurar uma regra:

1. **Nome do formulario**: selecione da lista de formularios detectados
2. **Acao**: escolha entre:
   - **Resposta Pronta**: envia uma mensagem pre-configurada
   - **Template WhatsApp**: envia template oficial da Cloud API (com variaveis)
3. **Tags**: aplique tags automaticamente ao contato
4. **Automacao**: dispare uma automacao do LionChat

## Formularios Suportados

| Plugin | Suporte |
|--------|---------|
| Elementor Pro Forms | Completo |
| Contact Form 7 | Completo |
| WPForms | Completo |

## Atualizacao

Para atualizar o plugin:
1. Baixe a nova versao do link acima
2. No WordPress: **Plugins > Adicionar Novo > Enviar Plugin**
3. Selecione o zip novo e confirme a substituicao
4. As configuracoes sao mantidas (salvas no banco de dados)

## Requisitos

- WordPress 5.0+
- PHP 7.0+
- Conta ativa no LionChat

## Versao

Atual: **2.5**

## Changelog

### v2.5
- Tracking de campanhas (UTMs, gclid, fbclid, ctwa_*)
- Cookie persistente 30 dias com renovacao automatica
- Merge inteligente de custom_attributes na conversa
- Fix: sanitizacao XSS nos campos extras do formulario
- Flag Secure no cookie em HTTPS

### v2.4
- Suporte a templates WhatsApp Cloud API com variaveis
- Auto-deteccao de formularios
- Envio de respostas prontas automaticas
- Criacao automatica de contatos
- Suporte a Contact Form 7 e WPForms
