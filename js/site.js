
(function () {
  'use strict';

  const CHAVE_CARRINHO = 'mercearia_carrinho';
  const ESTADO = { categorias: [], unidades: [], produtos: [], usuario: null, servidorOk: false };

  function esc(t) {
    return String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function formatarReal(n) { return Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
  function primeiroNome(n) { return String(n || '').trim().split(/\s+/)[0] || ''; }
  function arquivoAtual() {
    const a = location.pathname.split('/').pop();
    return a === '' ? 'index.html' : a;
  }

  async function api(metodo, rota, corpo) {
    const opc = { method: metodo, credentials: 'same-origin', headers: { 'X-Requested-With': 'mercearia' } };
    if (corpo instanceof FormData) {
      opc.body = corpo;
    } else if (corpo !== undefined) {
      opc.headers['Content-Type'] = 'application/json';
      opc.body = JSON.stringify(corpo);
    }
    let resp;
    try {
      resp = await fetch('api/index.php?r=' + rota, opc);
    } catch (e) {
      const erro = new Error('Sem conexão com o servidor.');
      erro.rede = true;
      throw erro;
    }
    if (resp.status === 204) return null;
    let dados = null;
    try { dados = await resp.json(); } catch (e) {
      const erro = new Error('O servidor não respondeu como esperado. Confira se o PHP está funcionando.');
      erro.rede = true;
      throw erro;
    }
    if (!resp.ok) {
      const erro = new Error((dados && dados.erro) || 'Erro inesperado.');
      erro.status = resp.status;
      erro.dados = dados;
      throw erro;
    }
    return dados;
  }

  function mostrarBannerServidor(texto) {
    if (document.getElementById('aviso-servidor')) return;
    const div = document.createElement('div');
    div.id = 'aviso-servidor';
    div.className = 'aviso-servidor';
    div.setAttribute('role', 'alert');
    div.textContent = texto;
    document.body.insertBefore(div, document.body.firstChild);
  }

  async function carregar() {
    if (location.protocol === 'file:') {
      mostrarBannerServidor('Esta página foi aberta direto do arquivo. Para usar o banco de dados, acesse pelo servidor PHP (ex.: http://localhost/mercearia/).');
      return;
    }
    try {
      const [cat, eu] = await Promise.all([api('GET', 'catalogo'), api('GET', 'eu')]);
      ESTADO.categorias = cat.categorias;
      ESTADO.unidades = cat.unidades;
      ESTADO.produtos = cat.produtos;
      ESTADO.usuario = eu.usuario;
      ESTADO.servidorOk = true;
    } catch (e) {
      mostrarBannerServidor(e.rede
        ? 'O servidor não respondeu. Confira se o Apache/PHP e o MySQL estão ligados e se o arquivo api/config.php está correto.'
        : e.message);
    }
  }

  let tempoAviso;
  function avisar(texto) {
    let el = document.getElementById('aviso');
    if (!el) {
      el = document.createElement('div');
      el.id = 'aviso';
      el.className = 'aviso-flutuante';
      el.setAttribute('role', 'status');
      document.body.appendChild(el);
    }
    el.textContent = texto;
    el.classList.add('mostrar');
    clearTimeout(tempoAviso);
    tempoAviso = setTimeout(() => el.classList.remove('mostrar'), 2200);
  }

  function produtoPorId(id) { return ESTADO.produtos.find((p) => p.id === String(id)); }
  function emojiDe(p) {
    if (p.emoji) return p.emoji;
    const c = ESTADO.categorias.find((x) => x.nome === p.categoria);
    return c ? c.emoji : '🛒';
  }
  function imagemProdutoHTML(p) {
    return p.imagem
      ? '<img src="' + esc(p.imagem) + '" alt="' + esc(p.nome) + '" loading="lazy">'
      : '<span aria-hidden="true">' + esc(emojiDe(p)) + '</span>';
  }

  function lerCarrinho() {
    try {
      const bruto = JSON.parse(localStorage.getItem(CHAVE_CARRINHO) || '{}');
      const limpo = {};
      Object.keys(bruto).forEach((id) => {
        const q = Number(bruto[id]);
        if (/^\d+$/.test(id) && Number.isInteger(q) && q > 0) limpo[id] = Math.min(q, 999);
      });
      return limpo;
    } catch (e) { return memoriaCarrinho; }
  }
  let memoriaCarrinho = {};
  function gravarCarrinho(c) {
    memoriaCarrinho = c;
    try { localStorage.setItem(CHAVE_CARRINHO, JSON.stringify(c)); } catch (e) { /* sem armazenamento: vale só nesta página */ }
    document.dispatchEvent(new CustomEvent('carrinho'));
  }
  function adicionarAoCarrinho(id, qtd) {
    const c = lerCarrinho();
    c[id] = Math.min(999, (c[id] || 0) + qtd);
    gravarCarrinho(c);
  }
  function definirQuantidade(id, qtd) {
    const c = lerCarrinho();
    if (qtd <= 0) delete c[id]; else c[id] = Math.min(999, qtd);
    gravarCarrinho(c);
  }
  function limparCarrinho() { gravarCarrinho({}); }
  function linhasCarrinho() {
    const c = lerCarrinho();
    return Object.keys(c).map((id) => ({ produto: produtoPorId(id), quantidade: c[id], id }))
      .filter((l) => l.produto);
  }
  function totalCarrinho() { return linhasCarrinho().reduce((s, l) => s + Math.round(l.produto.preco * 100) * l.quantidade, 0) / 100; }
  function itensCarrinho() { return linhasCarrinho().reduce((s, l) => s + l.quantidade, 0); }

  function montarMenu() {
    const nav = document.querySelector('nav.menu');
    if (!nav) return;
    const atual = arquivoAtual();
    const u = ESTADO.usuario;
    const link = (arq, texto) => '<a href="' + arq + '"' + (atual === arq ? ' class="ativo" aria-current="page"' : '') + '>' + texto + '</a>';
    let html = link('index.html', 'Início') + link('produtos.html', 'Produtos') + link('sobre.html', 'Sobre') + link('contato.html', 'Contato');
    if (u && u.admin) {
      const noAdmin = atual === 'dashboard.html' || atual === 'cadastro-produto.html';
      html += '<details class="menu-admin' + (noAdmin ? ' ativo' : '') + '"><summary>Admin</summary><div class="submenu-admin">' +
        link('dashboard.html', 'Dashboard') + link('cadastro-produto.html', 'Cadastrar produto') + '</div></details>';
    }
    html += link('login.html', u ? 'Olá, ' + esc(primeiroNome(u.nome)) : 'Login');
    nav.innerHTML = html;
  }

  function montarCarrinhoFlutuante() {
    if (document.body.hasAttribute('data-sem-carrinho')) return;

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'cf-botao';
    botao.setAttribute('aria-haspopup', 'dialog');
    botao.setAttribute('aria-expanded', 'false');
    botao.innerHTML = '<span aria-hidden="true">🛒</span><span class="cf-rotulo">Carrinho</span><span class="selo" hidden>0</span>';

    const fundo = document.createElement('div');
    fundo.className = 'cf-fundo';
    fundo.hidden = true;

    const gaveta = document.createElement('aside');
    gaveta.className = 'cf-gaveta';
    gaveta.setAttribute('role', 'dialog');
    gaveta.setAttribute('aria-modal', 'true');
    gaveta.setAttribute('aria-label', 'Seu carrinho');
    gaveta.hidden = true;
    gaveta.innerHTML =
      '<div class="cf-topo"><h2>Seu carrinho</h2><button type="button" class="cf-fechar" aria-label="Fechar carrinho">✕</button></div>' +
      '<div class="cf-corpo" aria-live="polite"></div>' +
      '<div class="cf-rodape"></div>';

    document.body.append(botao, fundo, gaveta);
    const selo = botao.querySelector('.selo');
    const corpo = gaveta.querySelector('.cf-corpo');
    const rodape = gaveta.querySelector('.cf-rodape');
    let ultimoPedido = null;
    let enviando = false;

    function desenhar() {
      const n = itensCarrinho();
      selo.textContent = n > 99 ? '99+' : String(n);
      selo.hidden = n === 0;
      botao.setAttribute('aria-label', 'Abrir carrinho, ' + n + (n === 1 ? ' item' : ' itens'));

      if (ultimoPedido) {
        corpo.innerHTML = '<div class="confirmacao"><div class="grande" aria-hidden="true">✅</div>' +
          '<h3>Pedido #' + ultimoPedido.numero + ' registrado!</h3>' +
          '<ul>' + ultimoPedido.itens.map((i) => '<li>' + i.quantidade + '× ' + esc(i.nome) + ' — ' + formatarReal(i.preco * i.quantidade) + '</li>').join('') + '</ul>' +
          '<p><strong>Total: ' + formatarReal(ultimoPedido.total) + '</strong></p></div>';
        rodape.innerHTML = '<button type="button" class="botao" data-cf="novo">Continuar comprando</button>';
        return;
      }

      const linhas = linhasCarrinho();
      if (!linhas.length) {
        corpo.innerHTML = '<p class="vazio">Seu carrinho está vazio.<br>Escolha produtos na página <a href="produtos.html">Produtos</a>.</p>';
        rodape.innerHTML = '';
        return;
      }
      corpo.innerHTML = linhas.map((l) =>
        '<div class="item-carrinho" data-id="' + l.id + '">' +
          '<div class="item-img">' + imagemProdutoHTML(l.produto) + '</div>' +
          '<div class="item-info"><div class="item-nome">' + esc(l.produto.nome) + '</div>' +
            '<div class="item-meta">' + formatarReal(l.produto.preco) + ' / ' + esc(l.produto.unidade) + '</div>' +
            '<button type="button" class="remover" data-cf="remover">Remover</button></div>' +
          '<div class="contador compacto"><button type="button" data-cf="menos" aria-label="Diminuir quantidade de ' + esc(l.produto.nome) + '">−</button>' +
            '<span class="qtd">' + l.quantidade + '</span>' +
            '<button type="button" data-cf="mais" aria-label="Aumentar quantidade de ' + esc(l.produto.nome) + '">+</button></div>' +
          '<div class="item-subtotal">' + formatarReal(l.produto.preco * l.quantidade) + '</div>' +
        '</div>').join('');
      rodape.innerHTML =
        '<div class="resumo-total"><span>Total</span><span class="valor">' + formatarReal(totalCarrinho()) + '</span></div>' +
        '<p class="mensagem-login" id="cf-msg" role="status"></p>' +
        '<button type="button" class="botao" data-cf="finalizar"' + (enviando ? ' disabled' : '') + '>' + (enviando ? 'Enviando...' : 'Finalizar compra') + '</button>' +
        '<button type="button" class="remover cf-limpar" data-cf="limpar">Esvaziar carrinho</button>';
    }

    let focoAnterior = null;
    function abrir() {
      focoAnterior = document.activeElement;
      fundo.hidden = false; gaveta.hidden = false;
      requestAnimationFrame(() => { fundo.classList.add('aberto'); gaveta.classList.add('aberto'); });
      botao.setAttribute('aria-expanded', 'true');
      document.body.classList.add('cf-travado');
      gaveta.querySelector('.cf-fechar').focus();
    }
    function fechar() {
      fundo.classList.remove('aberto'); gaveta.classList.remove('aberto');
      botao.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('cf-travado');
      setTimeout(() => { if (!gaveta.classList.contains('aberto')) { fundo.hidden = true; gaveta.hidden = true; } }, 250);
      if (focoAnterior && focoAnterior.focus) focoAnterior.focus();
    }
    botao.addEventListener('click', () => { if (gaveta.hidden) abrir(); else fechar(); });
    fundo.addEventListener('click', fechar);
    gaveta.querySelector('.cf-fechar').addEventListener('click', fechar);
    document.addEventListener('keydown', (e) => {
      if (gaveta.hidden) return;
      if (e.key === 'Escape') { fechar(); return; }
      if (e.key === 'Tab') {            
        const foco = Array.from(gaveta.querySelectorAll('button:not([disabled]), a[href]'));
        if (!foco.length) return;
        const primeiro = foco[0], ultimo = foco[foco.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
        else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
      }
    });

    gaveta.addEventListener('click', async (e) => {
      const alvo = e.target.closest('[data-cf]');
      if (!alvo) return;
      const acao = alvo.dataset.cf;
      const linha = alvo.closest('.item-carrinho');
      const id = linha && linha.dataset.id;
      const c = lerCarrinho();
      if (acao === 'mais') definirQuantidade(id, (c[id] || 0) + 1);
      else if (acao === 'menos') definirQuantidade(id, (c[id] || 0) - 1);
      else if (acao === 'remover') definirQuantidade(id, 0);
      else if (acao === 'limpar') limparCarrinho();
      else if (acao === 'novo') { ultimoPedido = null; desenhar(); }
      else if (acao === 'finalizar') await finalizar();
    });

    async function finalizar() {
      if (enviando) return;
      const itens = linhasCarrinho().map((l) => ({ id: l.id, quantidade: l.quantidade }));
      if (!itens.length) return;
      enviando = true; desenhar();
      try {
        const r = await api('POST', 'pedido', { itens });
        ultimoPedido = r.pedido;
        limparCarrinho();
      } catch (e) {
        if (e.dados && e.dados.indisponiveis) {
          const c = lerCarrinho();
          e.dados.indisponiveis.forEach((id) => delete c[id]);
          gravarCarrinho(c);
        }
        enviando = false; desenhar();
        const msg = document.getElementById('cf-msg');
        if (msg) { msg.textContent = e.message; msg.classList.add('mostrar', 'erro'); }
        return;
      }
      enviando = false; desenhar();
    }

    document.addEventListener('carrinho', () => { if (!ultimoPedido) desenhar(); else desenhar(); });
    window.addEventListener('storage', (e) => { if (e.key === CHAVE_CARRINHO) desenhar(); });
    desenhar();
  }

  function contarAcesso() {
    if (document.body.hasAttribute('data-sem-analytics') || location.protocol === 'file:') return;
    api('POST', 'acesso', { pagina: arquivoAtual() }).catch(() => {});
  }

  const pronto = carregar().then(() => {
    montarMenu();
    montarCarrinhoFlutuante();
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', contarAcesso); else contarAcesso();

  window.Mercearia = {
    ESTADO, pronto, api, esc, formatarReal, primeiroNome, avisar,
    produtoPorId, emojiDe, imagemProdutoHTML,
    adicionarAoCarrinho, definirQuantidade, lerCarrinho, limparCarrinho,
  };
})();
