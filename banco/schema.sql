SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS mercearia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mercearia;
CREATE TABLE IF NOT EXISTS usuarios (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome        VARCHAR(80)  NOT NULL,
  email       VARCHAR(120) NOT NULL,
  telefone    VARCHAR(30)  NOT NULL,
  senha_hash  VARCHAR(255) NOT NULL,
  admin       TINYINT(1)   NOT NULL DEFAULT 0,
  criado_em   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
  id        TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome      VARCHAR(40)  NOT NULL,
  emoji     VARCHAR(16)  NOT NULL DEFAULT '',
  imagem    VARCHAR(255) NOT NULL DEFAULT '',
  cor       VARCHAR(9)   NOT NULL DEFAULT '#E8DCC8',
  frase     VARCHAR(120) NOT NULL DEFAULT '',
  destaque  TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categorias_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS produtos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome          VARCHAR(60)  NOT NULL,
  descricao     VARCHAR(300) NOT NULL DEFAULT '',
  categoria_id  TINYINT UNSIGNED NOT NULL,
  preco         DECIMAL(10,2) NOT NULL,
  unidade       VARCHAR(20)  NOT NULL,
  emoji         VARCHAR(16)  NOT NULL DEFAULT '',
  imagem        VARCHAR(255) NOT NULL DEFAULT '',
  padrao        TINYINT(1)   NOT NULL DEFAULT 0,
  ativo         TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_produtos_ativo (ativo),
  CONSTRAINT fk_produtos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
  CONSTRAINT ck_produtos_preco CHECK (preco > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NULL,
  total       DECIMAL(12,2) NOT NULL,
  criado_em   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pedidos_usuario (usuario_id),
  CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedido_itens (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id       INT UNSIGNED NOT NULL,
  produto_id      INT UNSIGNED NOT NULL,
  nome            VARCHAR(60)  NOT NULL,
  preco_unitario  DECIMAL(10,2) NOT NULL,
  quantidade      SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_itens_pedido (pedido_id),
  KEY idx_itens_produto (produto_id),
  CONSTRAINT fk_itens_pedido  FOREIGN KEY (pedido_id)  REFERENCES pedidos (id) ON DELETE CASCADE,
  CONSTRAINT fk_itens_produto FOREIGN KEY (produto_id) REFERENCES produtos (id),
  CONSTRAINT ck_itens_quantidade CHECK (quantidade > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessoes_login (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  inicio      BIGINT NOT NULL,
  ultimo      BIGINT NOT NULL,
  fim         BIGINT NULL,
  PRIMARY KEY (id),
  KEY idx_sessoes_usuario (usuario_id),
  CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acessos (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pagina     VARCHAR(60) NOT NULL,
  visitante  VARCHAR(40) NOT NULL,
  dia        DATE        NOT NULL,
  criado_em  DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_acessos_dia (dia),
  KEY idx_acessos_visitante (visitante, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tentativas (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo       VARCHAR(20)  NOT NULL,
  chave      VARCHAR(190) NOT NULL,
  criado_em  BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_tentativas_busca (tipo, chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_mais_vendidos AS
SELECT produto_id,
       nome,
       SUM(quantidade)                 AS quantidade,
       SUM(quantidade * preco_unitario) AS receita
FROM pedido_itens
GROUP BY produto_id, nome;

INSERT IGNORE INTO categorias (id, nome, emoji, cor, frase, destaque) VALUES
  (1, 'Padaria',    '🥖', '#F2E0B6', 'Quentinho, todo dia de manhã.',      1),
  (2, 'Bebidas',    '🥤', '#CFE3DC', 'Do café da manhã ao fim de tarde.',  1),
  (3, 'Hortifruti', '🥬', '#DCE8C6', 'Fresquinho, direto do produtor.',    1),
  (4, 'Enlatados',  '🥫', '#F0D3C3', 'Praticidade para a sua despensa.',   1),
  (5, 'Cereais',    '🌾', '#EDE2BC', 'Arroz, feijão, aveia e muito mais.', 1),
  (6, 'Laticínios', '🧀', '#F4EBD0', 'Leite, queijos e ovos.',             0);

INSERT IGNORE INTO produtos (id, nome, descricao, categoria_id, preco, unidade, emoji, padrao, ativo, criado_em) VALUES
  ( 1, 'Pão Francês',           'Crocante por fora e macio por dentro, assado várias vezes ao dia.',            1,  0.90, 'unidade', '🥖', 1, 1, NOW()),
  ( 2, 'Pão de Forma 500g',     'Fatias macias para sanduíches e torradas do café da manhã.',                   1,  8.90, 'pacote',  '🍞', 1, 1, NOW()),
  ( 3, 'Bolo de Fubá',          'Bolo caseiro de fubá, fofinho e perfeito para acompanhar o café.',             1, 14.00, 'unidade', '🍰', 1, 1, NOW()),
  ( 4, 'Croissant',             'Folhado amanteigado, assado na hora.',                                         1,  6.50, 'unidade', '🥐', 1, 1, NOW()),
  ( 5, 'Café Torrado 500g',     'Café torrado e moído de sabor encorpado, pronto para passar.',                 2, 15.50, 'pacote',  '☕', 1, 1, NOW()),
  ( 6, 'Suco de Laranja 1L',    'Suco de laranja pronto para beber, em caixa de 1 litro.',                      2,  7.90, 'caixa',   '🧃', 1, 1, NOW()),
  ( 7, 'Refrigerante Cola 2L',  'Refrigerante sabor cola, garrafa de 2 litros para a família toda.',            2,  9.50, 'garrafa', '🥤', 1, 1, NOW()),
  ( 8, 'Água Mineral 1,5L',     'Água mineral sem gás, garrafa de 1,5 litro.',                                  2,  3.20, 'garrafa', '💧', 1, 1, NOW()),
  ( 9, 'Tomate',                'Tomates firmes e vermelhos, ótimos para molhos e saladas.',                    3,  6.90, 'kg',      '🍅', 1, 1, NOW()),
  (10, 'Banana Prata',          'Banana prata madura no ponto, doce e macia.',                                  3,  4.50, 'kg',      '🍌', 1, 1, NOW()),
  (11, 'Cenoura',               'Cenoura fresca e crocante, ótima crua, ralada ou cozida.',                     3,  3.80, 'kg',      '🥕', 1, 1, NOW()),
  (12, 'Alface Crespa',         'Pé de alface crespa fresquinho, colhido nesta semana.',                        3,  3.50, 'unidade', '🥬', 1, 1, NOW()),
  (13, 'Milho Verde 170g',      'Milho verde em conserva, pronto para saladas e refogados.',                    4,  4.20, 'lata',    '🌽', 1, 1, NOW()),
  (14, 'Ervilha 170g',          'Ervilhas tenras em conserva, prontas para usar.',                              4,  4.00, 'lata',    '🥫', 1, 1, NOW()),
  (15, 'Sardinha em Óleo 125g', 'Sardinha em óleo comestível, prática para lanches e receitas.',                4,  6.50, 'lata',    '🐟', 1, 1, NOW()),
  (16, 'Atum Sólido 170g',      'Atum em pedaços sólidos, ótimo em saladas e sanduíches.',                      4,  9.90, 'lata',    '🐟', 1, 1, NOW()),
  (17, 'Arroz Branco 5kg',      'Arroz branco tipo 1, grãos soltinhos, em pacote econômico de 5 kg.',           5, 24.90, 'pacote',  '🍚', 1, 1, NOW()),
  (18, 'Feijão Carioca 1kg',    'Feijão carioca selecionado, cozimento rápido e caldo grosso.',                 5,  8.50, 'pacote',  '🫘', 1, 1, NOW()),
  (19, 'Aveia em Flocos 500g',  'Aveia em flocos finos para mingaus, vitaminas e receitas.',                    5,  6.80, 'pacote',  '🌾', 1, 1, NOW()),
  (20, 'Granola 400g',          'Granola crocante com mel e frutas secas.',                                     5, 12.90, 'pacote',  '🥣', 1, 1, NOW()),
  (21, 'Leite Integral 1L',     'Leite integral longa vida, caixa de 1 litro.',                                 6,  5.20, 'caixa',   '🥛', 1, 1, NOW()),
  (22, 'Queijo Minas 500g',     'Queijo minas frescal, macio e levemente salgado.',                             6, 18.00, 'peça',    '🧀', 1, 1, NOW()),
  (23, 'Ovos Caipiras',         'Ovos caipiras de galinhas criadas soltas, vendidos por dúzia.',                6, 12.00, 'dúzia',   '🥚', 1, 1, NOW());
