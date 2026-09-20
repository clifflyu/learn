# 03 · MySQL（Q13–Q24）

> 配套 `../php-senior-interview-top50.md`。所有数字来自 `learn-mysql` 容器实测（**MySQL 8.4.11**，`innodb_page_size=16384`，`innodb_adaptive_hash_index=0`），脚本在 `../bench/mysql/`。
>
> 测法说明：本次实验和另一个 agent 共用同一个 MySQL 实例（它在另一个库上持续写入），凡是用到**全局计数器或墙上时间**的地方都做了抗污染处理——
> - 能用**会话级计数器**（`Handler_*`、`Sort_*`）就用会话级，它不受其他会话影响；
> - `Innodb_buffer_pool_read_requests` 是全局的、`FLUSH STATUS` 清不掉，所以用「窗口内跑 n 次求增量 ÷ n」+「多窗口取最小值」+「`SELECT 1` 做污染基线」；
> - 干净窗口数都标在表里，读者可以自己判断可信度。

---

## Q13. 为什么 MySQL 索引用 B+Tree，而不是 B-Tree 或 Hash？

### 结论

因为索引结构要同时满足三件事，只有 B+Tree 全占：

| 需求 | B-Tree | B+Tree | Hash |
| --- | --- | --- | --- |
| 等值查询 | 快 | 快 | **最快**（O(1)） |
| 范围查询 / `ORDER BY` | 差（要中序回溯） | **好**（叶子是双向链表，顺着走） | 不支持 |
| 磁盘 IO 次数 | 多（每个节点都存数据，扇出小） | **少**（非叶节点只存键+页号，扇出大） | 1 次 |
| 排序 / 最左前缀 | 不支持 | **支持**（键有序） | 完全不支持 |

一句话：**Hash 只会等值，B-Tree 的扇出被「数据」占了**。B+Tree 把数据全部下沉到叶子、叶子之间串成链表，非叶节点就只剩「键 + 子页号」，扇出从几十涨到上千，树高被压到 3~4 层，范围查询顺着链表走就行。

### 一、两种树长什么样

```
   B-Tree（数据可能出现在任何一层，范围查询要回到上层）        B+Tree（数据只在叶子，叶子连成链表）

            [10│30]                    ← 内部节点也带 data         [10│30]              ← 内部节点只有键+页号
          /   │   \                                             /   │   \
     [5│10] [20│30] [40│50]                                [1│5] [10│20] [30│40]
      ↑data   ↑data   ↑data                            叶:[1,5]→[10,20]→[30,40]   ← 双向链表，范围扫描顺着走

   扇出 = 页大小 ÷ (键 + 页号 + **整行数据**)              扇出 = 页大小 ÷ (键 + 页号) ≈ 16384 ÷ 13~18 ≈ 910~1260
```

**Hash 索引在 InnoDB 里根本不能手动建**（只有 Memory 引擎支持 `USING HASH`）。InnoDB 那个「自适应哈希索引 AHI」是引擎自己维护的，**只对等值查询生效**，范围查询一律走 B+Tree。所以「为什么不用 Hash」对 InnoDB 来说其实是个伪命题——它本来就有 Hash，只不过只当 B+Tree 的等值加速层用。

### 二、实测（MySQL 8.4.11）

**1）树高 = 一次主键点查读几个页。** 造一串行宽相同、行数递增的表，看这个数怎么跳变：

| 行数 | 主键点查页/次（60 窗口取最小） | 干净窗口 | 层数 |
| ---: | ---: | ---: | :--- |
| 320 | **2.000** | 52/60 | 2（根 + 1 叶页） |
| 50,000 | **2.000** | 57/60 | 2 |
| 200,000 | **2.000** | 22/60 | 2（正好卡在跳变点，见下） |
| 500,000 | **3.000** | 54/60 | 3 |
| 1,000,000 | **3.000** | 12/60 | 3 |

（`bench/mysql/08-btree-height.php`。污染基线：`SELECT 1` 真值 0 页，实测最小值 **0.0000 页/次**。）

**关于「干净窗口」这一列**：本次实验全程和另一个 agent 共用一个 MySQL 实例，它在另一个库上持续写入，会污染全局页计数器。判据是「同一个测量在 60 个窗口里有几个窗口给出了完全相同的最小值」——
**干净窗口越多越可信**。同一脚本在负载最重时重跑过一次，200000 档变成 3.000（29/60）、1000000 档变成 4.719（只有 1/60 个窗口）——后者的 1/60 明确说明它被污染了，不作为结论。表里取的是**多轮里最干净的那轮**。

**扇出算术对得上。** 这里要用**真实页数**（`information_schema.INNODB_TABLESTATS.CLUST_INDEX_SIZE`）来验证，不要用 `DATA_LENGTH/TABLE_ROWS` —— 后者是采样估算值，实测极不稳定（同一张表两次跑出 72 B/行 和 23.7 B/行，`每叶页行数` 在 317 / 16666 / 692 / 379 之间乱跳）。真实页数很稳（`bench/mysql/08b-btree-pages.php`）：

| 行数 | 聚簇索引页数（实测） | 每页行数（= 行数 ÷ 页数） |
| ---: | ---: | ---: |
| 320 | 3 | 106.7 ← 根页也在里面，样本太小 |
| 50,000 | 225 | 222.2 |
| 200,000 | 865 | 231.2 |
| 500,000 | 2,212 | **226.0** |
| 1,000,000 | 4,390 | **227.8** |

**每页行数稳定在 222~231，也就是「一个 16 KB 叶页装约 227 行」。** 非叶层一条记录 = PK 4 B + 子页号 4 B + 记录头 ≈ 13~18 B → 根页扇出 ≈ **910~1260**。叶页数超过扇出就要再加一层：

- 200,000 行 → 865 个叶页 < 910 → **2 层**（正好卡在临界点，所以这一档在多轮测量里反复横跳 2/3）；
- 500,000 行 → 2,212 个叶页 > 1260 → **3 层**（实测点查页数从 2.000 跳到 3.000）。

**跳变点 ≈ 20.6 万 ~ 28.6 万行，实测落在 200,000 和 500,000 之间，和上面「主键点查页数」那张表完全吻合。** 两条独立的证据链（点查页数、真实页数）指向同一个结论。

**2）各种访问方式的逻辑页数**（`bench/mysql/07-btree.php`，每项 2000 次/窗口取最小，`users` 表 498,653 行）：

| 访问方式 | 逻辑页/次 | 干净窗口 | 说明 |
| --- | ---: | ---: | --- |
| 主键点查（同形状的 50 万行表，见上表） | **3.000** | 54/60 | = 树高 |
| 主键范围扫 100 行 | **13.000** | 2/2 | 2600/2600 |
| 主键范围扫 1,000 行 | **28.000** | 2/2 | 5600/5600 |
| 主键范围扫 10,000 行 | **73.000** | 2/2 | 14608/14600 |
| `idx_city_age` 覆盖扫 2,000 行 | **20.000** | 3/3 | 10000/10003/10000 |
| `idx_email` + 回表 | **14.000** | 3/3 | 28000/28000/28000 |
| 全表扫描（`SELECT SUM(LENGTH(name))`） | **2689.000** | 3/3 | 2689/2689/2689 |

**3）会话级 Handler 计数器**（不受其他会话干扰，`FLUSH STATUS` 可清）：

| SQL | `read_key` | `read_next` | `read_rnd_next` |
| --- | ---: | ---: | ---: |
| `WHERE id = 12345`（主键点查） | **1** | 0 | 0 |
| `WHERE id BETWEEN 100000 AND 100999`（范围 1000 行） | 1 | **1000** | 0 |
| `WHERE city='北京' AND age=30`（`idx_city_age` 覆盖扫 2000 行） | 1 | **2000** | 0 |
| `WHERE city='北京' AND age=30`（要 `name`，回表） | 1 | 2000 | 0（回表算在 `read_key` 侧） |
| 全表扫 50 万行 | 1 | 0 | **500001** |
| 按无索引列 `name='user1'` 查 | 1 | 0 | **500001** |

`read_next` = 顺着索引链表读下一行。点查是 `read_key=1`，范围扫是 `read_next=N`——这就是「B+Tree 叶子是链表」的直接证据：范围查询不需要回到上层节点。

`INNODB_TABLESTATS` 给出的实际页数也印证了全表扫那个数：

```
NUM_ROWS=498653   聚簇索引=2212 页   二级索引合计=2277 页
DATA_LENGTH = 36241408 = 2212 × 16384   ← DATA_LENGTH 就是「聚簇索引页数 × 页大小」
全表扫描实测 2689 页/次 ÷ 2212 页 = 1.216（多出来的是扫描时的预读）
```

**4）页数比值**：全表扫 2689 页 ÷ 点查 3 页 = **896 倍**。这就是「索引把 O(N) 变成 O(log N)」在页这个单位上的实际样子。

### 三、五个反直觉的点

**1. 树高非常矮，而且是「一跳一跳」的，不是平滑增长。**

50 万行才 3 层，100 万行**还是** 3 层（实测）。因为扇出 ≈ 910~1260，3 层的 B+Tree 能装 $910^2 \times 227 \approx 1.88$ 亿行。这意味着：

- 「数据量涨到 2 倍所以变慢了」在**主键点查**上几乎不成立——3 层还是 3 层；
- 真要变慢的是**范围查询和全表扫描**，它们和行数成正比，跟树高无关；
- 面试里说「B+Tree 高度一般 3~4 层能存千万级数据」是对的，本次实测给了具体跳变点。

**2. 一次点查读 3 页，不等于 3 次磁盘 IO。**

`Innodb_buffer_pool_read_requests` 数的是**逻辑页访问**（含命中缓冲池的）。3 层的树里，根页和非叶页几乎永远在 buffer pool 里，真正可能触发物理 IO 的只有那个叶子页，所以**物理 IO 通常是 1 次**。「树高 3」和「磁盘 IO 3 次」是两回事，八股文里经常混着说。

**3. 二级索引的页数（14）不是「树高」，别拿它当层数用。**

`idx_email` 回表点查实测 14.000 页/次，比聚簇索引点查的 3.000 高了近 5 倍，但 `idx_email` 的树高同样是 3。多出来的是**重新定位、范围边界探测、预读**。所以「用页数反推树高」这个技巧只对**聚簇索引的点查**成立——这也是本脚本把点查页数当作唯一树高指标的原因。

**4. 1000 行的范围扫描，读的页不是 1 页。**

`WHERE id BETWEEN 100000 AND 100999` 命中 1000 行，但一次点查只有 3 页——范围查询要顺着叶子链表走过这 1000 行所在的**全部叶子页**（≈ 1000 ÷ 227 ≈ 5 个叶页 + 上层定位）。`Handler_read_next=1000` 说明扫描是「按行」推进的，页数则取决于这些行在物理上占了几页。**范围查询的代价按「行数 ÷ 每页行数」走，不是按树高走。**

**5. AHI（自适应哈希索引）默认就是开的，但你的等值查询未必吃到它。**

AHI 只对「同一张表、同一个等值查询模式、连续命中多次」才建，而且只加速等值。本次为了让「页数 = 树高」这个测量成立，全程 `innodb_adaptive_hash_index=0`。生产上开 AHI 会让热点等值查询更快，但**它也是 buffer pool 里的一块额外开销**，在写多读少、或者模式极多的场景下反而可能是负担——8.0 之后 AHI 默认仍然是 ON，但要多留意它的 `Innodb_adaptive_hash_*` 指标。

### 四、实战结论

| 场景 | 做法 | 依据 |
| --- | --- | --- |
| 主键点查 | 放心用，树高只有 3 层 | 实测 3.000 页/次（50 万行） |
| 范围 / 排序查询 | 设计索引让范围列有序，吃掉 `ORDER BY` | 叶子链表顺序扫，`read_next` 推进 |
| 等值 + 高并发热点 | 可依赖 AHI，但别把它当设计前提 | AHI 只优化等值，且需要重复命中模式 |
| 「数据量大了要不要分片」 | 单看主键点查，B+Tree 撑到亿级都不用分 | 3 层 ≈ 1.88 亿行 |
| 该担心的是 | 范围扫描、全表扫描、回表次数 | 2689 页 vs 3 页 = 896 倍 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| B+Tree 和 B-Tree 的区别？ | 非叶节点不存数据（扇出大）+ 叶子有链表（范围快）。就这两条，别的都是推论 |
| 为什么不用红黑树 / AVL？ | 二叉树一个节点一次磁盘 IO，100 万行要 20 层 = 20 次 IO；B+Tree 3 次 |
| 为什么不用跳表？ | 跳表是内存结构，层数和随机性带来的空间开销在磁盘上没有意义；Redis 用跳表是因为它在内存里且要 ZSet 范围查 |
| 为什么不用 LSM-Tree？ | 写放大更小、写更快，但读要查多层 + 合并；MySQL 选的是一致性读性能，不是写吞吐（RocksDB / TiDB 才用） |
| 页大小能改吗？ | `innodb_page_size` 建库时定死（4/8/16/32/64K），本实例 16384。改大 → 扇出大、树矮，但单页 IO 变大 |
| 主键用 UUID 行不行？ | 见 Q14 反直觉 2——会同时撑大所有二级索引，且插入变成随机写引发页分裂 |

---

## Q14. 什么是聚簇索引、二级索引、回表、覆盖索引？

### 结论

InnoDB 是**索引组织表**：数据本身就长在主键索引的叶子上，这叫聚簇索引。**其他所有索引的叶子上存的是「索引列 + 主键」**，不是行地址。所以：

- **拿主键查** = 一次 B+Tree 查找；
- **拿二级索引查** = 先在二级索引上找到主键，**再拿主键回聚簇索引查一次** → 两次 B+Tree 查找，这叫**回表**；
- 如果 `SELECT` 要的列二级索引里全有，就**不用回表**，这叫**覆盖索引**。

实测差距：`users`（498,653 行）上走二级索引 `idx_email` 取一行，**要 14.000 个逻辑页**，而同样的行数走主键点查只要 **3.000 页**——**多出 4.7 倍**，这多出来的就是回表。而且回表是「命中一行回一次」，命中 2000 行就是 2000 次回表，差距随命中行数线性放大。

### 一、两种索引的叶子各存什么

```
                         聚簇索引（PRIMARY）                     二级索引（idx_city_age）

                              [ 北京│30 ]                            [ 北京│30 ]
                             /     |      \                         /     |      \
                    ┌────────┐  ┌────────┐  ┌────────┐       ┌────────┐  ┌────────┐
           叶页 ───► │ id=5   │→ │ id=17  │→ │ id=88  │       │ 北京│30│→ │ 北京│31│→ ...
                    │ 北京│30 │  │ 北京│30 │  │ 北京│30 │       │  id=5  │  │  id=17 │
                    │ 整行数据│  │ 整行数据│  │ 整行数据│       │  ↑只有主键 │  │        │
                    │ (name, │  │ (name,  │  │ (name,  │       └────────┘  └────────┘
                    │  email…)|  │  email…)|  │  email…)│
                    └────────┘  └────────┘  └────────┘
                        ↑ 数据在这里                          ↑ 这里没有整行，只有主键
                        找到即返回                            拿到 id=5 还要再去左边查一次
                                                              ============ 回表 ============
```

**回表的代价是「每命中一行回一次」，不是「整批回一次」**：范围扫命中 2000 行，就是 2000 次聚簇索引点查。

```
  二级索引范围扫 N 行 → 得到 N 个主键 → 逐行回聚簇索引点查 → 每行 3 页

  覆盖索引：            [北京│30] → 直接读索引里的 age  → 结束（0 次回表）
  回表：                [北京│30] → 得到 id=5 → 回聚簇索引 → 读整行 → 取 name
```

### 二、实测（MySQL 8.4.11）

**1）页数对比**（`bench/mysql/07-btree.php`，每项 2000 次/窗口取最小）：

| 查询 | 逻辑页/次 | 干净窗口 |
| --- | ---: | ---: |
| 主键点查（50 万行同形状表） | **3.000** | 54/60 |
| `SELECT name FROM users WHERE email = 'u12345@example.com'`（`idx_email` + 回表取 `name`） | **14.000** | 3/3 |
| 全表扫描做对照 | **2689.000** | 3/3 |

**2）EXPLAIN 里的证据**（`bench/mysql/03-pagination.sql` 的 `EXPLAIN ANALYZE`）：

| 查询 | 计划 |
| --- | --- |
| `SELECT COUNT(*) ... WHERE city='北京' AND age=30` | `Covering index range scan on users using idx_city_age` —— **没有 Index lookup** |
| `SELECT SUM(LENGTH(name)) ... WHERE city='北京' AND age=30` | 上面那行 + **`Index lookup on users using PRIMARY (id=...)`** —— 回表 |

`EXPLAIN` 里认覆盖索引只看一个地方：**`Extra` 列有没有 `Using index`**。有它就是真覆盖（不回表），没有就是每行都要回。

**3）覆盖 vs 回表的耗时差**（`bench/mysql/12-icp.php`，`t_abc` 表 20 万行，`SELECT SUM(LENGTH(d)) FROM t_abc WHERE a=1 AND b>2 AND c=3`）：

| | 单次耗时（5 次取最小） | `Extra` |
| --- | ---: | --- |
| ICP 开（默认） | **5.086 ms** | `Using index condition` |
| ICP 关 | **71.731 ms** | `Using where` |
| 倍数 | **14.1×** | |

（`bench/mysql/12-icp.php`。这条同时是 Q17 的追问：ICP = 把 `WHERE` 里能用索引列判断的部分**下推到引擎层**，在索引上先过滤掉再回表，实测直接少了 14 倍的回表工作。）

### 三、四个反直觉的点

**1. 回表是「一行一次」，不是「一次搞定」。**

很多人以为回表是「查完索引再整体查一次表」。实际是 N 行就 N 次聚簇索引点查。所以**回表次数 = 二级索引命中的行数**，这才是回表真正的成本公式。缩小二级索引范围（让命中行数少）比优化回表本身有效得多。

**2. 二级索引里存的是主键，所以主键越长，所有二级索引一起变胖。**

InnoDB 二级索引的叶子 = `索引列 + 主键`。主键用 `BIGINT` 是 8 B，用 `CHAR(36)` 的 UUID 就是 36 B（还要算字符集开销）——**每一个二级索引的每一条记录都多这么多**，而且回表时比较的键变长。实测 `users` 表：`idx_city_age` 的 `key_len=131`，如果主键换成 UUID，这个数还会涨。

**3. 「加了索引」不等于「覆盖索引」。**

覆盖与否取决于**这条 SQL 的 `SELECT` 列**，不是索引本身。同一张表：

| SQL | 是否覆盖 |
| --- | --- |
| `SELECT COUNT(*) FROM users WHERE city='北京' AND age=30` | ✅ 覆盖（`COUNT(*)` 只要行数） |
| `SELECT age FROM users WHERE city='北京' AND age=30` | ✅ 覆盖（`age` 在索引里） |
| `SELECT age, city FROM users WHERE ...` | ✅ 覆盖（都在索引里） |
| `SELECT SUM(LENGTH(name)) FROM users WHERE ...` | ❌ 要 `name`，必须回表 |

所以「**SELECT 只取需要的列**」这条规范的真实收益就是：让本来能覆盖的查询真覆盖上。`SELECT *` 几乎永远不可能覆盖。

**4. InnoDB 官方术语里没有「回表」这个词。**

中文社区叫回表，英文对应的是 **bookmark lookup** 或 EXPLAIN 里的 **`Index lookup on ... using PRIMARY`**。面试时如果能说出 `EXPLAIN ANALYZE` 输出里那行长什么样，比背定义有效。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 列表页展示 | 用覆盖索引兜住高频查询，禁止 `SELECT *` |
| 分页列表 | 见 Q22：先只取主键（覆盖），再回表取整行 |
| 主键选择 | 短、单调递增（`BIGINT AUTO_INCREMENT`）；不要 UUID、不要长字符串 |
| 联合索引列顺序 | 尽量让最常用的 `SELECT` 列和 `WHERE` 列落在同一个索引里 |
| 判断是否覆盖 | 只看 `EXPLAIN` 的 `Extra` 有没有 `Using index` |
| 回表太多次 | 先想「能不能缩小命中行数」，再想「能不能补列进索引」 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么 InnoDB 用聚簇索引，MyISAM 不用？ | MyISAM 是堆表，索引叶子存行号（物理偏移），主键和二级索引地位平等；代价是「行移动」会让索引失效，且没有聚簇局部性 |
| 没有主键的表呢？ | InnoDB 会建隐藏的 6 字节 `DB_ROW_ID` 当聚簇索引键——等于强制有了聚簇索引，且你控制不了 |
| 二级索引叶子上的主键重复怎么办？ | 不重复存「行」，存「索引列 + 主键」，主键相同索引列不同的组合是不同记录；所以二级索引的「唯一」要靠 `UNIQUE` 约束保证 |
| 覆盖索引能省掉什么？ | 省掉 N 次聚簇索引点查；实测同一形状的查询，带 ICP 的索引条件下推和纯回表相差 **14.1×** |
| 索引下推（ICP）和覆盖索引什么关系？ | ICP 优化的是「回表之前先过滤」，覆盖索引优化的是「根本不回表」。两者可叠加 |

---

## Q15. 联合索引的最左前缀原则是什么？索引字段顺序怎么设计？

### 结论

联合索引 `(a, b, c)` 的排序规则是「**先按 a 排，a 相同的按 b 排，b 相同的按 c 排**」。所以它天然只能按 `a → b → c` 这个顺序定位：

- 能定位的：`a` / `a,b` / `a,b,c`（**连续的、从最左开始的**前缀）；
- 不能定位的：`b` / `c` / `b,c`（跳过了 `a`）；
- **一旦某一列用了范围条件（`>`、`<`、`BETWEEN`、`LIKE 'x%'`），它后面的列就失去定位能力**，只能做 ICP 过滤。

字段顺序的设计口诀：**等值列在前 → 范围列在后 → 排序列紧跟着等值列**。

### 一、`(a, b, c)` 在磁盘上的实际顺序

```
  a=1,b=2,c=3
  a=1,b=2,c=7     ← a 相同，b 有序；b 相同，c 有序
  a=1,b=2,c=9
  a=1,b=5,c=1     ← a 相同，b 跳到 5 → b 仍然有序
  a=1,b=5,c=4
  a=2,b=1,c=8     ← a 变化 → b 重新从小开始（b 只在 a 内部有序！）
  a=2,b=3,c=2
  a=3,b=1,c=1

  可以定位：  a=1              → 一段连续区间          ✅
              a=1 AND b=2      → 一段连续区间          ✅
              a=1 AND b=2 AND c=3 → 一个点             ✅
  不能定位：  b=2              → 散落在每个 a 段里     ❌ 只能全索引扫描
              c=3              → 同上                  ❌
              a=1 AND c=3      → a 能定位，c 不能       ⚠️ c 降级为 ICP 过滤

  范围之后断档：
              a=1 AND b>2 AND c=3
                        └─ b 用了范围 → b 之后的 c 不能参与定位
                           索引区间只能开到 (a=1, b>2)，c=3 只能回表前过滤
```

### 二、实测（MySQL 8.4.11）

`bench/mysql/09-leftmost.sql`。表 `t_abc(a INT, b INT, c INT, d VARCHAR(64))`，20 万行，`a` 基数 10、`b` 基数 97、`c` 基数 997（用不同模数造，避免相关性），索引 `KEY idx_abc (a, b, c)`。

**1）`key_len` 直接告诉你「用到了几列」**（`a`、`b` 都是 `INT` 4 B，`c` 是 `INT` 4 B）：

| WHERE 条件 | `type` | `key` | `key_len` | 用到的列 |
| --- | --- | --- | ---: | --- |
| `a = 1` | `ref` | `idx_abc` | **4** | a |
| `a = 1 AND b = 2` | `ref` | `idx_abc` | **8** | a, b |
| `a = 1 AND b = 2 AND c = 3` | `ref` | `idx_abc` | **12** | a, b, c |
| `b = 2` | `ALL` | NULL | — | **一列都用不上** |
| `c = 3` | `ALL` | NULL | — | 一列都用不上 |
| `b = 2 AND c = 3` | `ALL` | NULL | — | 一列都用不上 |
| `a = 1 AND c = 3` | `ref` | `idx_abc` | **4** | 只有 a，`c` 降级（Extra 出现 `Using index condition`） |
| `c = 3 AND b = 2 AND a = 1`（乱序写） | `ref` | `idx_abc` | **12** | a, b, c —— **优化器会重排** |
| `a = 1 AND b > 2 AND c = 3` | `range` | `idx_abc` | **8** | 只有 a, b；`c` 断档 |

`key_len` 是最硬的证据：`4 / 8 / 12` 就是 `INT` 的 4 字节 × 用到的列数。

**2）`ORDER BY` 也吃最左前缀**（会话级计数器）：

| SQL | `Handler_read_next` | 排序方式 | Extra |
| --- | ---: | --- | --- |
| `WHERE a=1 ORDER BY b, c LIMIT 10` | **9** | **无需排序** | 无 filesort |
| `WHERE a=1 ORDER BY b DESC, c DESC LIMIT 10` | 0（走 `read_prev`） | 反向扫描 | `Backward index scan` |
| `WHERE a=1 ORDER BY b ASC, c DESC LIMIT 10` | — | 必须排序 | `Using filesort` |
| `ORDER BY c`（缺 a、b） | **20000** | 必须排序 | `Using filesort`，`Sort_scan=1`，`Sort_rows=10` |

第一行 `read_next=9` 意思是「为了取 10 行只往前走了 9 步」——**完全没排序**。最后一行读了 20000 行才排出 10 行。

### 三、四个反直觉的点

**1. `a=1 AND b>2 AND c=3` 里的 `c=3` 用不上定位——范围一出现，后面全断档。**

这是最常在面试里答错的一条。很多人以为「三个列都在 `WHERE` 里，索引就全用上了」。实测 `key_len=8` 而不是 12，`type=range` 而不是 `ref`。`c=3` 确实还在索引里（所以能靠 ICP 过滤，不用回表后才过滤），但**它不参与定位索引区间**。

推论：如果你有 `WHERE a=? AND b>? AND c=?` 这种形状，且 `c` 的区分度很高，把索引改成 `(a, c, b)` 可能更好——但这要按实际查询分布算，不能背。

**2. 条件的书写顺序完全不影响，优化器会重排。**

`WHERE c=3 AND b=2 AND a=1` 实测 `key_len=12`，和正序写一模一样。所以「最左前缀」说的是**索引定义里的列顺序**，不是 SQL 里的书写顺序。面试时把这两个混为一谈是硬伤。

**3. `ORDER BY b DESC, c DESC` 能用索引，但 `ORDER BY b ASC, c DESC` 不能。**

因为索引在物理上只按一个方向有序，`DESC, DESC` 整体反向扫一遍就行（`Backward index scan`），而 `ASC, DESC` 是混合方向——除非建 `(a, b ASC, c DESC)` 这种降序索引，否则只能 filesort。MySQL 8.0+ 支持真正的降序索引，这是 8.0 的一个实用增量。

**4. 最左前缀不是「优化器的规矩」，是「B+Tree 的物理必然」。**

索引就是一张按 `(a,b,c)` 排好序的表。跳过 `a` 去找 `b`，等于在一本按「姓-名」排序的花名册里按「名」找人——书本身没有这个索引，只能一页页翻。理解到这一层，所有「失效场景」都不用背了。

### 四、实战结论

| 场景 | 索引怎么设计 |
| --- | --- |
| 固定几列等值 + 一个范围 | `(等值列..., 范围列)`，范围列放最后 |
| 等值 + 排序 | `(等值列, 排序列)`，排序就能被索引吃掉 |
| 多列都有范围 | 挑区分度最高的那个放最左（在等值列之后），其余靠 ICP |
| 只有 `ORDER BY c` 没有 `WHERE a` | 单列索引 `(c)`，别指望 `(a,b,c)` |
| 高频分页 | 见 Q22：`(过滤列, 排序列, 主键)` 让延迟关联全走覆盖索引 |
| 判断用了几列 | 看 `EXPLAIN` 的 `key_len`，除以单列字节数 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么范围后面的列不能定位？ | B+Tree 是有序的：`b>2` 是一段区间，区间内 `c` 在**不同 b 值内部**分别有序，不是全局有序，没法二分 |
| 那 `b>2 AND c=3` 就白写了？ | 没白写，能做 ICP 在索引层过滤（见 Q14 实测 11.9×），只是不能缩小索引区间 |
| `(a,b,c)` 上 `WHERE a=1` 和 `WHERE a=1 AND b=2` 谁快？ | 后者快，`key_len` 8 定位更精确、扫的行更少；但如果 `a=1` 本身就只命中几十行，差距可以忽略 |
| 建 `(a,b)` 和 `(b,a)` 两个索引？ | 冗余。`(a,b)` 已能服务 `a` 和 `a,b`；真要服务 `b` 单列才需要 `(b)`。索引不是越多越好，每个索引都拖慢写 |
| `LIKE 'x%'` 算等值还是范围？ | 范围。所以它后面的列同样断档 |
| 联合索引最多几列？ | 16 列（MySQL 限制），但实际 3~5 列后就该考虑是不是拆查询了 |

---

## Q16. 哪些情况会导致索引失效？

### 结论

「失效」有两种，性质完全不同，面试时必须分开说：

| 类型 | 本质 | 例子 | 证据 |
| --- | --- | --- | --- |
| **真失效** | 索引列被运算/转换，**索引区间根本没法构造**，加 `FORCE INDEX` 也没用 | `DATE(created_at)='2026-01-01'` | `FORCE INDEX` 后仍然 `type=ALL`、`key=NULL` |
| **假失效（成本选择）** | 索引**可用**，但优化器算完成本觉得全表扫更便宜 | `city='北京' AND age>20` | `FORCE INDEX` 后 `type=range`、`key_len=131` |

判断方法很简单：**加 `FORCE INDEX` 再 `EXPLAIN` 一次**。还能走索引的，就是成本选择；仍然 `ALL` 的，才是真失效。

### 一、失效的机理：索引里存的是「值」，不是「函数的值」

```
  索引 idx_created 里存的是：        created_at 的原值，按原值排序
                                    ┌──────────────────────────┐
                                    │ 2026-01-01 00:00:03      │
                                    │ 2026-01-01 09:31:17      │  ← 有序，可以二分
                                    │ 2026-01-02 08:04:55      │
                                    └──────────────────────────┘

  WHERE DATE(created_at) = '2026-01-01'
        └─ 这是给每一行算一遍 DATE() 再比较
           DATE(created_at) 的值在索引里的位置，和 created_at 的排序**没有对应关系**
           → 无法构造区间 → 只能逐行算 → 全表扫

  能走索引的等价写法： created_at >= '2026-01-01' AND created_at < '2026-01-02'
                        └─ 直接是在 created_at 上开区间，完美对应索引的有序性
```

**一句话：任何让「列的原值」变成「列经过计算的值」的写法，都会让索引失效。** 函数、算术、隐式类型转换，本质都一样。

### 二、实测（MySQL 8.4.11）

`bench/mysql/10-index-fail.sql`，基于 `learn.users`（500,000 行，索引 `idx_city_age(city,age)`、`idx_email(email)`、`idx_created(created_at)`）。

**1）列上做运算 → 真失效**

| SQL | `type` | `possible_keys` | `key` | 加 `FORCE INDEX` 后 |
| --- | --- | --- | --- | --- |
| `WHERE DATE(created_at) = '2026-01-01'` | `ALL` | **NULL** | NULL | **仍然 `ALL` / `key=NULL`** |
| `WHERE created_at >= '2026-01-01' AND created_at < '2026-01-02'` | `range` | `idx_created` | `idx_created` | `key_len=5`，`rows=1370`，`Using index condition` |
| `WHERE YEAR(created_at) = 2026` | `ALL` | NULL | NULL | — |
| `WHERE created_at > NOW() - INTERVAL 1 DAY` | `range` | `idx_created` | `idx_created` | `rows=1369` |

注意第一行的 `possible_keys` 是 **NULL**——不是「有索引但没选」，是**优化器认为压根没有可用索引**。这就是「真失效」的标志。

**2）隐式类型转换 → 真失效，而且会静默算错**

`email` 是 `VARCHAR(64)`：

| SQL | `type` | `key` | 结果 |
| --- | --- | --- | --- |
| `WHERE email = 12345`（数字） | `ALL` | NULL（但 `possible_keys=idx_email`） | 全表扫，等价于把每行的 email 转成数字比 |
| `WHERE id = '12345'`（字符串比 INT 主键） | `const` | PRIMARY | 正常，**这是唯一安全的转换方向** |

真正的坑在这里：

```sql
SELECT * FROM users WHERE id = '12345abc';
-- type = const，key = PRIMARY，返回 id = 12345 那一行
-- SHOW WARNINGS: Warning 1292 Truncated incorrect DOUBLE value: '12345abc'
```

**它把 `'12345abc'` 截断成 `12345` 去查了，而且查到了行、返回了结果**。只有一个 `Warning 1292`，**不报错**——大多数跑在 autocommit 下的应用根本不会去看 `SHOW WARNINGS`，于是这就是一次静默的错误结果。这就是为什么「`varchar` 列和数字比较」在 MySQL 里先是数据事故，然后才是性能问题。

**3）前置通配符 → 真失效，但只有前置**

| SQL | `type` | `key_len` |
| --- | --- | --- |
| `WHERE email LIKE '%12345@example.com'` | `ALL` | — |
| `WHERE email LIKE 'u12345%'` | `range` | 258 |

`'abc%'` 是前缀，能对应索引区间；`'%abc'` 是后缀，索引按前缀排序，后缀无序。

**4）`OR` → 不必然失效，`index_merge` 会救它**

| SQL | 结果 |
| --- | --- |
| `WHERE city='北京' OR email='u1@example.com'` | `ALL`（两边都命中了 20% 和 1 行，但 city 侧太宽，合并比全表扫还贵） |
| 同上 + `/*+ INDEX_MERGE(users idx_city_age, idx_email) */` | **`index_merge` / `Using sort_union(idx_city_age,idx_email)`**，`key_len=130,258` |
| `WHERE id BETWEEN 1 AND 5 OR email='u1@example.com'` | **`index_merge` / `Using union(PRIMARY,idx_email)`**，两边都是等值/小范围 |
| `WHERE created_at > '2026-09-19' OR email='u1@example.com'` | **`index_merge` / `Using sort_union(idx_created,idx_email)`** |
| `WHERE city='北京' OR name='user1'`（`name` 无索引） | `ALL` —— 有一边没索引就只能全表扫 |

`union` 和 `sort_union` 的区别：两边都是等值/唯一区间用 `union`，有一边是范围（结果需要去重排序）用 `sort_union`。

**5）`!=` / `NOT IN` → 不是失效，是「全索引扫描」**

| SQL | `type` | `key` | `rows` |
| --- | --- | --- | --- |
| `WHERE city <> '北京'` | `index` | `idx_city_age` | 498653 |
| `WHERE city NOT IN ('北京')` | `index` | `idx_city_age` | 498653 |

`type=index` 是**扫整个索引树**（不是 `ALL` 扫整张表）。它比全表扫略轻（只读索引页，且可能覆盖），但**代价仍然是 O(N)**，和「走索引」是两回事。

**6）范围太宽 → 优化器主动放弃（假失效）**

| SQL | 默认 | 加 `FORCE INDEX (idx_city_age)` |
| --- | --- | --- |
| `WHERE city='北京' AND age>20` | `ALL` | **`range`，`key_len=131`，`Using index condition`** |

`FORCE INDEX` 之后立刻变成 `range` —— 说明索引完全可用，只是优化器算下来「命中 19 万行，走索引还不如直接扫」。这是**成本选择**，不是失效。

**7）跳过最左列 → 看似失效，但 8.0 有 skip scan**

`age` 是 `idx_city_age(city, age)` 的第二列：

| SQL | 结果 |
| --- | --- |
| `SELECT age FROM users WHERE age = 30` | **`range` + `Using index for skip scan`**，`rows=49865` |
| 同上，但 `SET SESSION optimizer_switch='skip_scan=off'` | `type=index`（全索引扫），`rows=498653`，`Using where; Using index` |
| `SELECT * FROM users WHERE age = 30` | `ALL` |

这是本次实测最反直觉的一条：**跳过了最左列，优化器也能靠 skip scan 把索引用起来**（把 `city` 的每个不同值当成一次独立的前缀探测）。代价是探测次数 = `city` 的基数（本例 5 个），所以只在**最左列基数很低**时才划算。`SELECT *` 就退化成 `ALL`，因为要回表。

### 三、四个反直觉的点

**1. `FORCE INDEX` 都救不了的，才是真失效；能救的都是成本选择。**

这一条把「索引失效」这个含混的概念一刀切开。面试里如果只答「用了函数会失效」，遇到 `city='北京' AND age>20` 这种就会误判。**判断方法：`FORCE INDEX` + `EXPLAIN` 再来一次。**

**2. 隐式类型转换是「静默的」，不报错不警告。**

`WHERE id = '12345abc'` 返回了 `id=12345` 这一行，`SHOW WARNINGS` 空。这不是优化问题，是**结果正确性问题**。反过来 `WHERE email = 12345` 则是性能问题（全表扫）。两个方向危害不同，但都源于同一条规则：**字符串和数字比较时，MySQL 把字符串转成数字（不是把数字转成字符串）**。

推论：应用层传参一律用与列类型一致的绑定参数，PDO 的 `ATTR_EMULATE_PREPARES=false` + 强类型绑定能挡住大部分。

**3. `<>` / `NOT IN` 走的是 `type=index`，不是 `ALL`——别一概说「失效」。**

实测 `rows=498653`、`key=idx_city_age`。它确实用上了索引（全索引扫描，比全表扫轻），只是**不能缩小范围**。说「失效」不够准确，说「无法用索引区间过滤，退化为全索引扫描」才是准确的。真正能优化的写法是改业务语义（比如枚举出「要哪些城市」而不是「不要哪个城市」）。

**4. `OR` 的救星是 `index_merge`，而它有前提。**

必须**每个 `OR` 分支都能用上索引**，否则整体退化为全表扫（`city='北京' OR name='user1'` 实测 `ALL`）。而且 `index_merge` 有额外代价（要归并去重），当某一分支命中比例太高（如 `city='北京'` 命中 20%）时，优化器宁可全表扫。真要拆，`UNION ALL` + 应用层去重往往比等优化器开恩更可控。

**5. 8.0 的 skip scan 让「最左前缀」不再是铁律。**

实测 `WHERE age=30`（跳过最左列 `city`）能走 `range + Using index for skip scan`，`rows` 从 498653 降到 49865（**降到 1/10**）。能生效的条件：最左列基数低 + 被跳过后的列在自己的范围内可用 + 不需要回表（所以要 `SELECT age` 而不是 `SELECT *`）。别在老版本上依赖它（8.0.13 才有）。

### 四、实战结论

| 现象 | 判定 | 处理 |
| --- | --- | --- |
| 列上套函数 | 真失效 | 改写成范围：`DATE(c)='d'` → `c >= 'd 00:00:00' AND c < 'd+1'` |
| 隐式类型转换 | 真失效 + 可能算错 | 参数类型与列类型一致；`varchar` 列绝不用数字查 |
| 前置 `%` | 真失效 | 换前缀 `LIKE 'x%'`；或用全文索引 / ES |
| 显示 `possible_keys=NULL` | 真失效 | 索引不可用，必须改 SQL |
| 显示 `possible_keys` 有值但 `key=NULL` | 成本选择 | 先看 `rows`；真需要就 `FORCE INDEX` 验证，但别长期依赖 |
| `type=index` + `key_len` 很长 | 全索引扫描 | 不是「走索引」；想办法加过滤条件 |
| `OR` 各分支都有索引 | 可能 `index_merge` | 试 `/*+ INDEX_MERGE(...) */`；不行就 `UNION ALL` |
| 跳过最左列但 `SELECT` 列在索引里 | 8.0 skip scan 可能生效 | 让查询变成覆盖查询，成功率大增 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么函数会导致失效？ | 索引按列的**原值**排序；函数破坏了「有序 → 可二分」的对应关系 |
| `LIKE 'abc%'` 为什么可以？ | 前缀有序，可以算出一个索引区间 `['abc', 'abd')` |
| `LIKE '%abc%'` 有救吗？ | 8.0 起可以试试 `LIKE` + 覆盖索引（全索引扫描仍比全表扫轻）；根治要全文索引或外部搜索引擎 |
| `OR` 改 `UNION` 一定更快吗？ | 不一定。两边都能走索引且各命中少量行才快；命中多行时 `index_merge` 的归并代价可能超过全表扫 |
| `FORCE INDEX` 能上生产吗？ | 能，但要配监控。它是把优化器的判断权拿过来，数据分布变了可能翻车 |
| 怎么系统性发现失效的 SQL？ | `performance_schema.events_statements_summary_by_digest` 里 `SUM_NO_INDEX_USED > 0` 的 digest（见 Q21） |

---

## Q17. `EXPLAIN` 主要看哪些字段？`type` 和 `Extra` 怎么解读？

### 结论

按**信息量从大到小**排，只看四个字段就够：

| 顺序 | 字段 | 回答什么问题 |
| ---: | --- | --- |
| 1 | `type` | **怎么访问的**——决定这一段的量级（`const` → `ALL` 差几个数量级） |
| 2 | `key` + `key_len` | 用了哪个索引、**用到了几列**（`key` 为 NULL 说明没走索引） |
| 3 | `rows` × `filtered` | 优化器**估算**要扫多少行、过滤后剩多少 |
| 4 | `Extra` | 有没有回表 / 排序 / 临时表 / 索引下推 |

`type` 的好坏阶梯（本次全部实测造出来了）：

```
  好 ┌──────────────────────────────────────────────────────────────────────────┐ 差
     │ system > const > eq_ref > ref > range > index > ALL                       │
     │  ↑        ↑        ↑        ↑      ↑        ↑        ↑                   │
     │ 1行表  主键/唯一   join 被   非唯一  索引范围  全索引扫  全表扫            │
     │        等值定值   驱动表用   索引等值          (O(N))    (O(N)+回表)       │
     │                  唯一索引                                                │
     └──────────────────────────────────────────────────────────────────────────┘
      另外还有两个「跨类型」的：index_merge（多索引并集）、index_subquery 等
```

### 一、`type` 阶梯与 `Extra` 的语义

```
  ┌─ type ──────────────────────────────────────────────────────────────────────┐
  │ const     WHERE id = 12345               优化阶段就确定只有 1 行，只读 1 次   │
  │ eq_ref    JOIN ON o.id = u.id            被驱动表按唯一索引匹配，每行匹配 1 条│
  │ ref       WHERE city = '北京' AND age=30 非唯一索引等值，可能匹配多行        │
  │ range     WHERE created_at > '...'       索引上的一段区间                   │
  │ index     SELECT age FROM users WHERE age <> '北京'   扫全部索引项          │
  │ ALL       全表扫（聚簇索引叶子全读一遍）                                     │
  └─────────────────────────────────────────────────────────────────────────────┘

  ┌─ Extra ─────────────────────────────────────────────────────────────────────┐
  │ NULL                    索引里就能判定，不用回表、也不用 server 层再过滤      │
  │ Using index             【覆盖索引】要的列全在索引里 → 不回表                │
  │ Using index condition   【ICP】WHERE 里能用索引列判的部分下推到引擎层先过滤   │
  │ Using where             引擎把行返给 server 层后，server 层再过滤            │
  │ Using filesort          额外排序（吃不到索引顺序）                           │
  │ Using temporary         额外建临时表（GROUP BY / DISTINCT / UNION 用不上索引）│
  │ Using index for skip scan  【8.0】跳过最左列也能用上索引                    │
  └─────────────────────────────────────────────────────────────────────────────┘

  最容易混的三个：
    Using index           = 不用回表（省钱）
    Using index condition = 回表前先过滤（少回表）
    Using where           = 已经拿到行了，server 层最后再筛一遍（钱已经花了）
  「Using index condition」和「Using where」可以同时出现，但含义完全不同。
```

### 二、实测（MySQL 8.4.11）

`bench/mysql/11-explain.sql`，`learn.users` 498,653 行。

**1）`type` 阶梯逐个造出来**

| `type` | SQL 形状 | `key` | `key_len` | `rows` | `Extra` |
| --- | --- | --- | ---: | ---: | --- |
| `index`（拿不到 `system`） | `SELECT * FROM one_row`（1 行表） | PRIMARY | 4 | 1 | `Using index` |
| `const` | `WHERE id = 12345` | PRIMARY | 4 | 1 | NULL |
| `eq_ref` | `FROM users u JOIN one_row o ON o.id = u.id` | PRIMARY | 4 | 1 | `Using where` |
| `ref` | `WHERE city='北京' AND age=30` | `idx_city_age` | **131** | 2000 | NULL |
| `range` | `WHERE created_at > '2026-09-19'` | `idx_created` | **5** | 1370 | `Using index condition` |
| `index` | `SELECT age FROM users WHERE age = 30`（关掉 skip scan 后） | `idx_city_age` | 131 | 498653 | `Using where; Using index` |
| `ALL` | `WHERE name = 'user1'`（无索引列） | NULL | NULL | 498653 | `Using where` |
| `index_merge` | `WHERE id BETWEEN 1 AND 5 OR email='u1@example.com'` | PRIMARY,`idx_email` | 4,258 | 6 | `Using union(PRIMARY,idx_email); Using where` |

⚠️ **1 行的 InnoDB 表拿到的是 `index` 而不是 `system`**。`system` 只在**只有一行**的 MyISAM / Memory 表上出现；InnoDB 因为 MVCC 和聚簇索引，同样的查询给的是 `type=index` + `Extra=Using index`。很多八股文把 `system` 写在阶梯第一位，实际上在 InnoDB 上你基本见不到它。

**2）`key_len` = 「参与定位的键」的字节数**

| 索引列 | 类型 | `key_len` | 算法 |
| --- | --- | ---: | --- |
| `city` | `VARCHAR(32)` utf8mb4 | **130** | 32 × 4 + 2（长度字节）= 130 |
| `city` + `age` | `VARCHAR(32)` + `TINYINT` | **131** | 130 + 1 = 131 |
| `email` | `VARCHAR(64)` utf8mb4 | **258** | 64 × 4 + 2 = 258 |
| `created_at` | `DATETIME` | **5** | 5 字节定点表示 |

所以**`key_len` 能直接读出「这个联合索引用到了前几列」**（Q15 用的就是这个技巧）。注意：允许 `NULL` 的列每个还要 +1（NULL 标志位），本例的列都是 `NOT NULL`，所以没体现出来。

**3）`rows` 是估算，`filtered` 也是估算**

| SQL | `rows`（估算） | 实际行数 | 误差 |
| --- | ---: | ---: | --- |
| `WHERE city='北京'` | **193,776** | **100,000** | 估算偏大 **1.94 倍** |
| `WHERE city='北京' AND age=30` | **2,000** | **2,000** | 完全一致 |

为什么第一行差这么多？因为 `city` 的基数统计是**采样**出来的，且 `城市 × 年龄` 这两个列在数据上是「除 50 取模」相关的（不独立），优化器按独立假设乘出来的数偏高。**两列等值条件反而准**（因为在索引里能精确定位到一个点）。

**结论：`rows` 只能用来比较「两个方案谁的量级更小」，不能当行数用。** 要看真实行数必须用 `EXPLAIN ANALYZE`。

**4）`Extra` 四种取值的实测对照**

| SQL | `Extra` | 含义 |
| --- | --- | --- |
| `WHERE city='北京' AND age=30` | **NULL** | 索引里就能判定，不回表、不额外过滤 |
| `SELECT age ... WHERE city='北京' AND age=30` | **`Using index`** | 覆盖索引，不回表 |
| `SELECT name ... WHERE city='北京' AND age=30`，`FORCE INDEX` | **`Using index condition`** | ICP：在索引上先过滤再回表 |
| `WHERE name = 'user1'` | **`Using where`** | 全表扫完，server 层再筛 |
| `WHERE city='北京' GROUP BY age` | **`Using temporary; Using filesort`** | 既要临时表又要排序 |
| `SELECT age FROM users WHERE age = 30` | **`Using where; Using index for skip scan`** | 8.0 的 skip scan |

**5）三种 `EXPLAIN` 格式的差别**（同一条 `WHERE city='北京' AND age=30`）

```
--- FORMAT=TREE（默认，最像执行计划本身）---
-> Index lookup on users using idx_city_age (city='北京', age=30)  (cost=700 rows=2000)

--- FORMAT=JSON（给程序读，能拿到 used_key_parts / cost_info / used_columns）---
{ "query_block": { "cost_info": { "query_cost": "700.00" },
    "table": { "access_type": "ref", "key": "idx_city_age",
               "used_key_parts": ["city","age"], "key_length": "131",
               "rows_examined_per_scan": 2000, "filtered": "100.00",
               "cost_info": { "read_cost":"500.00", "eval_cost":"200.00", "prefix_cost":"700.00",
                              "data_read_per_join":"1M" } } } }

--- EXPLAIN ANALYZE（真跑一遍，给真实行数和真实耗时）---
-> Index lookup on users using idx_city_age (city='北京', age=30)
     (cost=700 rows=2000) (actual time=1.27..9.04 rows=2000 loops=1)
                            ^^^^^^^^^^^^^^^^^^^^^^^^^^^^ 这一段只有 ANALYZE 才有
```

`FORMAT=JSON` 里的 `used_key_parts` 是读索引「用了几列」最直接的地方，比 `key_len` 做除法更保险。

### 三、四个反直觉的点

**1. `rows` 是估算，可以差出近 2 倍——所以 `EXPLAIN` 只能定方向，不能定结论。**

实测 `WHERE city='北京'` 估 193,776、实际 100,000（差 1.94 倍）；而 `WHERE city='北京' AND age=30` 估 2,000、实际 2,000（完全一致）。**同一个索引、同一张表，估算精度差这么多**。原因：单列条件只能靠列上的采样统计，两列等值在索引里能精确定位到一个点。要看真数就用 `EXPLAIN ANALYZE`。

**2. InnoDB 上拿不到 `system`。**

1 行的 InnoDB 表实测是 `type=index` + `Extra=Using index`（因为要读聚簇索引）。`system` 是 MyISAM / Memory 的专利。背阶梯的时候知道这一点，面试官问「你见过 `system` 吗」就不会翻车。

**3. `Using where` 和 `Using index condition` 是钱花在「之前」还是「之后」。**

- `Using index condition`（ICP）：过滤**发生在回表之前**，被过滤掉的行根本不用回表 → 实测快 **14.1 倍**（见 Q14）；
- `Using where`：行已经拿到了（回表也做完了），server 层最后筛一遍 → **钱已经花了**。

所以看到 `Using where` 就要警惕：「这个条件是白筛的，能不能把它变成索引的一部分？」

**4. `type=index` 不是好事。**

它的意思是「扫全部索引项」，`rows` 实测 498,653——比 `ALL` 只省了「不用读整行」，量级完全一样是 O(N)。很多资料把 `type=index` 排在 `range` 后面一位就完事，实际上 `range` 和 `index` 之间有**数量级**的差距（1370 vs 498653 行）。

**5. `EXPLAIN ANALYZE` 的 `actual time` 是「每 loop 第一次/平均」两个数。**

`(actual time=1.27..9.04 rows=2000 loops=1)` 里的 `1.27` 是返回第一行的时间、`9.04` 是返回全部行的时间。这两个数相差大，说明「定位很快、但逐行取数据慢」（典型的回表或排序）。而 `(cost=700 rows=2000)` 是优化器的**估算**，和后面 `(actual ... rows=2000)` 是**两套东西**——前者估的、后者实测的，放在一起就是为了让你对比。

### 四、实战结论

| 看到 | 判断 | 动作 |
| --- | --- | --- |
| `type=ALL`，`key=NULL` | 没有可用索引 | 看 `WHERE` 是否触发 Q16 的失效条件 |
| `type=ALL`，`possible_keys` 有值 | 成本选择，不是失效 | `FORCE INDEX` 验证；或改写让范围更窄 |
| `type=index` | 全索引扫描，仍是 O(N) | 想办法把条件变成 `range` |
| `type=range` 但 `rows` 很大 | 范围太宽 | 缩范围，或按 Q15 调索引列顺序 |
| `key_len` 比预期小 | 联合索引后面的列没用上 | 检查是否有范围条件截断 |
| `Extra=Using index` | 覆盖索引，最优 | 保持 |
| `Extra=Using index condition` | ICP 生效 | 保持；也可试着补列变成真覆盖 |
| `Extra=Using where` 且 `type!=ALL` | 回表后还在筛 | 考虑把这个条件补进索引 |
| `Extra=Using temporary` | 临时表 | `GROUP BY` 列顺序对齐索引；或拆查询 |
| `Extra=Using filesort` | 排序没吃到索引 | 见 Q15 的排序列设计 |
| 估算和实际差很多 | 统计信息不准 | `ANALYZE TABLE`；`EXPLAIN ANALYZE` 看真数 |
| 排序 / 分页慢且 `rows` 很小 | 可能不是扫描慢 | 看 `actual time` 的第一行/全部行两个数 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| `filtered` 是什么？ | 估算的「过滤后剩余百分比」。`rows × filtered%` 才是交给下一阶段的行数；100% 说明这个阶段没有额外过滤 |
| `possible_keys` 和 `key` 不一致说明什么？ | 有可用索引但没选 = 成本选择（Q16 的「假失效」） |
| `Using filesort` 一定是磁盘排序吗？ | 不一定。名字叫 filesort，但数据量小时是内存排序；`Sort_merge_passes > 0` 才说明真的落盘归并了 |
| `EXPLAIN` 会真的执行 SQL 吗？ | 不会（8.0 之前）。`EXPLAIN ANALYZE` 会**真的跑**，别在生产对着大表随便用 |
| 怎么看到「每行到底读了几页」？ | EXPLAIN 看不到。要 `Handler_*` 会话计数器（见 Q13）或 `Innodb_buffer_pool_read_requests` 差分 |
| 为什么 `rows` 估不准？ | 索引统计是采样 + 列间独立假设；相关列、倾斜分布都会让估算偏离。8.0 有直方图 `ANALYZE TABLE ... UPDATE HISTOGRAM` 可以改善 |

---

## Q18. 事务的 ACID 分别由什么保证？四种隔离级别有什么区别？

### 结论

ACID 不是四个并列的特性，**A / C / I / D 各有各的机制，而且「隔离性」和「原子性」会互相打架**：

| 字母 | 含义 | InnoDB 靠什么 |
| --- | --- | --- |
| **A** 原子性 | 要么全成，要么全不成 | **undo log**（回滚日志）——记下「怎么撤销」，回滚时反向执行 |
| **C** 一致性 | 数据从一个合法状态到另一个合法状态 | **不是某个机制，是 A+I+D 的结果** + 业务约束（唯一索引、外键、CHECK） |
| **I** 隔离性 | 并发事务互不干扰 | **锁**（当前读）+ **MVCC**（快照读） |
| **D** 持久性 | 提交了就不丢 | **redo log**（WAL，先写日志再写数据页）+ `innodb_flush_log_at_trx_commit` |

**最容易答错的点：C 不是由某个机制保证的。** 它是 A、I、D 以及业务约束共同作用的结果——数据库能保证「数据不会因为你写一半崩了而错乱」，但保证不了「你从账户 A 转 100 到 B 而总数不变」（那是业务逻辑）。

四种隔离级别一句话区分（**只影响「读」**，写都是加锁的）：

| 级别 | 脏读 | 不可重复读 | 幻读 |
| --- | :---: | :---: | :---: |
| READ UNCOMMITTED | 可能 | 可能 | 可能 |
| READ COMMITTED | 不可能 | 可能 | 可能 |
| REPEATABLE READ（默认） | 不可能 | 不可能 | 不可能（InnoDB 用间隙锁额外堵上了） |
| SERIALIZABLE | 不可能 | 不可能 | 不可能 |

### 一、隔离级别到底差在哪

```
  READ UNCOMMITTED ── 直接读别人未提交的数据（连 undo 版本链都不走）
        │
        ▼  加一层「只读已提交的版本」
  READ COMMITTED   ── 每条语句开始时新建一个 ReadView
        │              → 同一事务里两次 SELECT 之间别人提交了，第二次能看见（不可重复读）
        │              → 也因为这个，RC 下不加间隙锁，性能更好，是很多互联网公司的选择
        ▼  把 ReadView 提升到「整个事务一份」
  REPEATABLE READ  ── 事务第一次读时建一个 ReadView，之后一直用它
        │              → 两次 SELECT 结果一致（快照读靠 MVCC）
        │              → 但当前读（FOR UPDATE）仍会看到新数据 → InnoDB 用「间隙锁」堵住幻读
        ▼  读也加锁
  SERIALIZABLE     ── 普通 SELECT 也变成「加共享锁的读」
                       → 实测：普通 SELECT 会给命中的行加 S 锁，写操作直接被挡
```

**关键：RR 的「不可重复读、幻读都不可能」是两套机制合作的结果**——快照读（普通 `SELECT`）靠 **MVCC 读视图**，当前读（`FOR UPDATE` / `UPDATE` / `DELETE`）靠**间隙锁**。只讲 MVCC 解释不了 RR 为什么没幻读（Q19 展开）。

### 二、实测（MySQL 8.4.11）

`bench/mysql/13-isolation.php`。两个连接 A、B，`innodb_lock_wait_timeout=2`，每个隔离级别跑同一组子测试。

| 隔离级别 | 脏读 | 不可重复读 | 幻读 | 写被挡 | A 的三次写耗时 | A 两次读同一行 | A 两次读同一范围 |
| --- | :---: | :---: | :---: | :---: | --- | --- | --- |
| READ UNCOMMITTED | **有** | **有** | **有** | 否 | 0.00s/0.00s/0.00s | 100 → **300** | 2 → **3** |
| READ COMMITTED | 无 | **有** | **有** | 否 | 0.00s/0.00s/0.00s | 100 → **300** | 2 → **3** |
| REPEATABLE READ | 无 | 无 | 无 | 否 | 0.00s/0.00s/0.00s | 100 → **100** | 2 → **2** |
| SERIALIZABLE | 无 | 无 | 无 | **是** | **2.00s/2.00s/2.00s** | 写被挡，100 → 100 | 写被挡，2 → 2 |

**读法**：
- `100 → 300` 表示 A 没提交时读到了 100，B 提交后 A 再读变成了 300（不可重复读）；
- `100 → 100` 表示两次读一致；
- `2 → 3` / `2 → 2` 是同一段范围两次 `COUNT(*)`，验证幻读；
- 「写被挡」那一列是 SERIALIZABLE 独有的：B 的写操作全部超时（`2.00s` = `innodb_lock_wait_timeout`）。

**SERIALIZABLE 为什么写被挡？** 看锁表：

```
--- SERIALIZABLE 下普通 SELECT 加了什么锁（performance_schema.data_locks）---
  iso            TABLE   IS              GRANTED            ← 表级意向共享锁
  iso  PRIMARY   RECORD  S,REC_NOT_GAP   GRANTED  LOCK_DATA=1  ← 行级共享锁！

--- 对照：REPEATABLE READ 下同样的普通 SELECT ---
  data_locks 里 iso 的锁数量: 0                          ← 一个锁都不加（纯快照读）

--- 对照：RR 下 SELECT ... FOR UPDATE ---
  iso            TABLE   IX              GRANTED
  iso  PRIMARY   RECORD  X,REC_NOT_GAP   GRANTED  LOCK_DATA=1
```

**同样一句普通 `SELECT`，SERIALIZABLE 下加了 `S,REC_NOT_GAP` 行锁，RR 下 0 个锁。** 这就是「隔离级别只影响读」这句话的具体含义——隔离级别往上调，是**把读也变成加锁的**。

### 三、四个反直觉的点

**1. 一致性（C）没有对应的机制，它是结果不是手段。**

面试里被问「C 靠什么保证」，正确答案是「**没有单独的机制**——靠 A（undo log 回滚）+ I（锁和 MVCC）+ D（redo log 不丢），再加上业务层约束（唯一索引、外键、CHECK）」。把 C 说成「靠某种日志」是错的。

**2. RC 和 RR 的差距不在「锁」，在「读视图什么时候建」。**

两个级别的快照读都走 MVCC，都不加锁（实测 RR 下普通 SELECT 是 0 个锁）。区别只有一个：**RC 每条语句新建 ReadView，RR 事务第一次读时建一次然后一直用**。这一个差别就导致了「不可重复读」。理解到这一层，RC/RR 就不用背了。

**3. RR 下「幻读被解决了」靠的不是 MVCC，是间隙锁。**

纯 MVCC 的逻辑是「我读我的快照」，那 B 插入的新行在 A 的快照里本来就不该出现——**快照读层面确实没有幻读**。但**当前读**（`SELECT ... FOR UPDATE`）读的是最新数据，新插入的行会「凭空出现」。所以 InnoDB 在 RR 下额外加**间隙锁**把区间锁住。代价是并发度下降、死锁概率上升——这也是很多公司改用 RC 的原因。

**4. 隔离级别越高，「读」越像「写」，性能和死锁率都变差。**

实测数据很直白：SERIALIZABLE 下 A 的**三次写全部超时 2 秒**（`2.00s/2.00s/2.00s`），而 RU / RC / RR 下都是 `0.00s`。原因是 A 的普通 `SELECT` 已经对行加了 S 锁，B 想要 X 锁就只能等。**「把隔离级别调高来解决问题」通常是错的**——正确做法是在业务层用乐观锁 / 唯一约束。

**5. 默认是 RR，但互联网业务更常用 RC。**

`@@transaction_isolation` 实测 `REPEATABLE-READ`。但 RC 有两个实际优势：(a) 不加间隙锁，锁范围小、死锁少；(b) 半一致性读让 `UPDATE ... WHERE` 在 RC 下可以「先读最新已提交版本再决定要不要锁」，减少无效加锁。代价是丢掉了「事务内可重复读」这个保证——如果你的业务代码依赖「事务里读两次结果一样」，改 RC 就会出 bug。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 默认选择 | 保持 RR；业务代码不要依赖「快照读」做判断后再写（那是 TOCTOU） |
| 高并发写入、死锁频发 | 评估改用 RC（权衡：丢可重复读，换更小锁范围） |
| 需要「读-判断-写」原子 | 用 `SELECT ... FOR UPDATE`（当前读）或乐观锁版本号，**不要**用普通 `SELECT` 后 `UPDATE` |
| 需要绝对串行 | 不要靠 `SERIALIZABLE`；用悲观锁（`FOR UPDATE`）+ 唯一约束 + 应用层排队 |
| 脏读 | 只在 RU 下可能；生产不要用 RU |
| 提交安全性 | `innodb_flush_log_at_trx_commit=1`（默认）保证不丢；调成 2/0 是拿持久性换吞吐 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 原子性靠什么？ | undo log。修改前先把「原值」写进 undo，回滚就是反向应用；MVCC 的旧版本也复用了同一份 undo |
| 持久性靠什么？ | redo log（WAL）。先顺序写 redo（快），后台再随机刷数据页（慢）；崩溃后用 redo 重放 |
| 为什么要有 redo 又要 undo？ | redo 保证「提交的不丢」，undo 保证「未提交的不生效」。方向相反，各管一半 |
| 两阶段提交是什么？ | redo log 和 binlog 的一致性：`prepare` → 写 binlog → `commit`。崩溃恢复时按 XID 对齐两边 |
| RC 和 RR 在读视图上的差别？ | RC：每条语句一个 ReadView；RR：事务第一个快照读时建一个，之后复用（见 Q19） |
| 为什么 RR 不能完全避免幻读？ | 严格说 InnoDB 的 RR 在「快照读 + 当前读混用」下仍可能出现语义上的幻读，标准里 RR 是允许幻读的，InnoDB 靠间隙锁做得比标准更严 |

---

## Q19. MVCC 是怎么实现的？RC 和 RR 的区别在哪？

### 结论

MVCC 的本质是「**每一行都存着自己的版本链，每个事务读的时候拿到一张可见性名单，按名单在版本链上挑一个自己能看的版本**」。它让「读」不用加锁，所以读不阻塞写、写不阻塞读。

RC 和 RR 的**唯一区别就是这张名单（ReadView）什么时候生成**：RC 每条语句生成一张，RR 整个事务只在第一次快照读时生成一张、之后一直复用。就这一行之差，造成了「不可重复读」和「可重复读」的全部差异。

要特别记住两件事，面试时说出来是加分项：
- **MVCC 只作用于「快照读」（普通 `SELECT`）**。`SELECT ... FOR UPDATE`、`UPDATE`、`DELETE` 是「当前读」，它们读的永远是最新已提交版本，并且加锁 —— 不走 MVCC。
- **`History list length` 涨的是「事务数」，不是「被改的行数」**。这一点我实测过，见下文反直觉点 3。

### 一、版本链 + ReadView

每行有两个隐藏列（`SHOW CREATE TABLE` 看不到，但 `EXPLAIN` 里能引用）：

```
行记录：  [ 真实列... | DB_TRX_ID (6B) | DB_ROLL_PTR (7B) ]
                          │                  │
                          │ 最后改这一行的事务 id
                          └──────────────────┴──> undo log 里的「上一个版本」
                                                  （旧值 + 再上一个版本的位置）
```

同一个 `id=1` 的行被多个事务依次改过之后，undo 里串成一条**版本链**（`v` 的历史值）：

```
当前数据页         undo log（回滚段）
  id=1, v=300  ──>  id=1, v=200  ──>  id=1, v=100
  trx_id=105                          trx_id=90        trx_id=80
      (最新)           (DB_ROLL_PTR 一路往回指)
```

一个事务要读 `id=1` 时，不能直接读当前行 —— 得先问「这一行对我可见吗」。判断依据就是 **ReadView**：

```
ReadView {
  m_ids        : 生成这一刻还「活跃」（未提交）的事务 id 列表
  min_trx_id   : m_ids 里最小的
  max_trx_id   : 下一个将要分配的事务 id（>= 这个的说明是生成之后才开始的）
  creator_trx_id: 自己
}

对版本链上某个版本的 trx_id 逐条判断：
  ① trx_id == creator_trx_id          → 可见（自己改的）
  ② trx_id <  min_trx_id              → 可见（早就提交了）
  ③ trx_id >= max_trx_id              → 不可见（我生成名单之后才开的事务）
  ④ min_trx_id <= trx_id < max_trx_id → 看 trx_id 在不在 m_ids 里
                                        在 = 还活着 = 不可见；不在 = 已提交 = 可见
若当前版本不可见，就顺着 DB_ROLL_PTR 找上一个版本，直到找到可见的
```

对 `id=1` 那条链 `[105] → [90] → [80]`，如果事务 A 的 ReadView 是 `m_ids=[105]`、`min=105`、`max=106`：
- `105` 在 `m_ids` 里 → 不可见，回退；
- `90 < 105` → 可见，读到 `v=200`。

**所以 MVCC 不用锁就能读到一个「历史快照」，代价是版本链要靠 undo 一直留着。**

**RC 和 RR 只差「生成时机」**

```
RC（READ COMMITTED）：每条 SELECT 生成一张新 ReadView
   BEGIN
   SELECT v FROM t WHERE id=1  ── 生成 ReadView#1 ──> v=100
   （别的事务改成了 200 并提交）
   SELECT v FROM t WHERE id=1  ── 生成 ReadView#2 ──> v=200   ← 变了！不可重复读
   COMMIT

RR（REPEATABLE READ）：事务内第一句快照读生成一张，之后一直复用
   BEGIN
   SELECT v FROM t WHERE id=1  ── 生成 ReadView#1 ──> v=100
   （别的事务改成了 200 并提交）
   SELECT v FROM t WHERE id=1  ── 复用 ReadView#1 ──> v=100   ← 没变，可重复读
   COMMIT
```

### 二、实测（MySQL 8.4.11）

**1）四种隔离级别的行为矩阵**（`bench/mysql/13-isolation.php`）：

| 隔离级别 | 脏读 | 不可重复读 | 幻读 | 写被挡 | A 三次写的耗时 | 同行读两次 | 范围两次 count |
| --- | --- | --- | --- | --- | --- | --- | --- |
| READ UNCOMMITTED | 有 | 有 | 有 | 否 | 0.00s/0.00s/0.00s | 100 → 300 | 2 → 3 |
| READ COMMITTED | 无 | **有** | 有 | 否 | 0.00s/0.00s/0.00s | **100 → 300** | 2 → 3 |
| REPEATABLE READ | 无 | **无** | 无 | 否 | 0.00s/0.00s/0.00s | **100 → 100** | **2 → 2** |
| SERIALIZABLE | 无 | 无 | 无 | **是** | **2.00s/2.00s/2.00s** | 写被挡，100 → 100 | 写被挡，2 → 2 |

「同行读两次」这一列就是 MVCC 的直接体感：RC 下第二次读到 300（别人改了），RR 下还是 100（快照没变）。

**2）SERIALIZABLE 的普通 SELECT 到底加了什么锁**（`performance_schema.data_locks`）：

```
SERIALIZABLE 下的普通 SELECT：
  iso          TABLE      IS               GRANTED  -
  iso          PRIMARY    RECORD  S,REC_NOT_GAP  GRANTED  1     ← 普通读也加了 S 锁！

对照 REPEATABLE READ 下同样的普通 SELECT：
  data_locks 里 iso 的锁数量: 0                                ← 一个锁都没有，纯 MVCC
```

这一条把「MVCC 只在 RR/RC 生效」讲透了：SERIALIZABLE 把快照读**降级成了加锁读**，所以它的读会阻塞别人的写。

**3）ReadView 是真的存在于 InnoDB 内部**（`SHOW ENGINE INNODB STATUS` 的 TRANSACTIONS 段）：

```
BEGIN;
SELECT v FROM hu WHERE id = 1;     -- 第一次快照读，生成 ReadView
SHOW ENGINE INNODB STATUS\G
  →  TRANSACTIONS
     Trx id counter 115753
     History list length 1
     LIST OF TRANSACTIONS FOR EACH SESSION:
     1 read views open inside InnoDB      ← 就是这个事务持有的那张名单
```

> **版本坑**：网上（含很多 5.7 / 8.0 早期博客）说这里会打印 `Trx read view will not see trx with id >= N, sees < M`。**MySQL 8.4 已经不打印这一行了**，只保留 `N read views open inside InnoDB` 这行汇总。我第一版脚本照着老格式 grep，结果什么都抓不到（脚本注释里记了这个坑）。

**4）`History list length` 计的是「事务数」，不是「行数」**（`bench/mysql/14b-history-unit.php`）：

先挂起一个 RR 事务并读一次（把读视图固定住，purge 被挡住），然后看不同形态的写各让这个数涨多少：

| 写操作 | 第一次跑 | 第二次跑 |
| --- | --- | --- |
| 1 个事务 UPDATE 2,000 行 | +1 | +1 |
| **2,000 个事务各 UPDATE 1 行** | **+2,039** | **+2,000** |
| 1 个事务 UPDATE 200,000 行 | +20 | +2 |
| 1 个事务把同一行 UPDATE 2,000 次 | +60 | +1 |
| 再 1 个事务 UPDATE 200,000 行 | +43 | +1 |

**结论非常清楚：2,000 个独立小事务稳定地把这个数顶高 2,000 左右（两次跑都一致），而单个改 20 万行的大事务只涨个位数到几十。** 它跟的是「事务 / undo 记录」的数量级，**不跟行数**。所以拿 `History list length` 除以行数去估算「积压了多少行」是错的。

（两次跑的绝对增量有差异 —— 这个指标本身受 purge 线程并发影响，但「按事务涨、不按行涨」这个**方向**两次完全一致。）

**5）挂起的读视图会让 purge 停住，释放后也不是立刻回收**（`14b-history-unit.php` 尾段）：

```
释放读视图后，每 0.5s 采一次 History list length：
  +0.0s  = 2013      ← 释放了，但完全不动
  ...
  +13.5s = 2014      ← 13.5 秒里几乎没动
  +16.0s = 2033
  +17.5s = 2038
  +18.0s = 6         ← 突然从 2038 掉到 6，半秒内清掉
  +18.5s = 12
```

**purge 是批量的、异步的**：不是「读视图一释放就线性回收」，而是攒着，然后某一刻一次性大批清理（这里 2038 → 6 只用了半秒）。**这解释了为什么线上「长事务刚杀掉」的那一瞬间 undo 不会立刻降下来**，别以为没生效就反复去 kill。

**6）长事务对 undo 表空间的实际影响**（`bench/mysql/14-mvcc.php`）：

```
mv 表 200,000 行，初始: History list length = 31, undo: innodb_undo_001=32MB innodb_undo_002=32MB

① 一个 RR 事务读了 1 行后一直挂着（读视图固定）：
   3 轮 UPDATE 20 万行后  History list 31 → 43（+12），耗时 6.5s，undo 仍 32MB/32MB

② 对照：同样 3 轮 UPDATE，但这个 RR 事务根本不读（没有读视图）：
   History list 45 → 2（-43），耗时 5.7s，undo 48MB/32MB
```

对照组的差异就是 MVCC 的成本来源：**有读视图 → 版本不能回收，History list 只涨不跌；没有读视图 → purge 追着回收，数字回到个位数。**

**7）长事务堆积下 undo 表空间真的会涨**（5 轮全表 UPDATE + 一个挂起的 RR 事务）：

```
innodb_undo_001 = 48.0MB   innodb_undo_002 = 32.0MB
        ↓ 5 轮全表 UPDATE
innodb_undo_001 = 48.0MB   innodb_undo_002 = 64.0MB      ← 第二个 undo 表空间翻倍
```

**8）当前活跃事务可以直接查**（`information_schema.INNODB_TRX`）：

```
trx_id=88744  state=RUNNING  isolation=REPEATABLE READ  rows_modified=1  started=2026-09-20 13:47:14
```

`trx_id` 就是版本链里那个 `DB_TRX_ID`。线上排查「谁把 purge 挡住了」就是查这张表按 `trx_started` 排序。

### 三、四个反直觉的点

**1. RC 的「不可重复读」不是 bug，是它更快的原因。**

RC 每条语句一个新 ReadView，意味着**读到的是最新已提交数据**，旧版本可以马上被 purge 掉，undo 不用积压。RR 要保一整条版本链，长事务下 undo 会持续膨胀。所以：

- 用 RC → undo 压力小、间隙锁少、死锁少，但业务不能依赖「事务内读两次结果一样」；
- 用 RR → 语义更舒服，但一个忘了提交的事务就能把 undo 撑大。

**2. `SELECT ... FOR UPDATE` 完全不看 MVCC，它读最新版本。**

这是最容易踩的坑：在 RR 事务里先普通 `SELECT` 拿到 `v=100`（快照），然后 `SELECT ... FOR UPDATE` 去锁这一行 —— 拿回来的是 **`v=300`（最新已提交）**，不是 100。因为当前读要读最新版本才能加正确的锁。

所以「先快照读判断，再当前读去改」这种写法，两次读到的值可能不一致，逻辑上等于 TOCTOU。要一致就必须**全部用当前读**（`FOR UPDATE` 起手）。

**3. 上面实测证明了：`History list length` 不是「积压行数」。**

2,000 个事务 → +2,000；1 个事务改 200,000 行 → +2。如果按「行数」理解，第二个应该涨 20 万，实际涨了个位数。**用它做容量告警可以（看趋势），用它除以行数估算积压量是错的。**

**4. 读视图不是「事务一开始就建」，而是「第一次快照读才建」。**

```
BEGIN;                          -- 此刻还没有 ReadView
SELECT ... ;                    -- ← 在这里建，m_ids 是此刻的活跃事务
```

后果：`BEGIN` 之后什么也不做、隔了 10 分钟才第一次 `SELECT`，那么这 10 分钟里别人提交的事务**全部可见**。想让快照「早点固定」，就必须在 `BEGIN` 之后立刻发一句 `SELECT`（或 `START TRANSACTION WITH CONSISTENT SNAPSHOT`）。

**5. `START TRANSACTION WITH CONSISTENT SNAPSHOT` 在 RC 下没有意义。**

因为它照样每条语句重新生成 ReadView —— 这个语法只在 RR 下有用。

**6. undo 的旧版本读完了也不能立刻删，因为可能还有别人要用。**

一个版本能不能删，取决于「**还有没有比它更老的活跃读视图**」。InnoDB 维护的是全局最老读视图位置，purge 只能清理比它更老的。所以一个挂了 1 小时的 RR 事务，能让这 1 小时内**所有**事务产生的 undo 都不能回收 —— 影响面远超这一个事务改的行数。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 隔离级别怎么选 | 默认 RR 即可；高并发写 + 死锁多的业务评估换 RC（换掉「事务内可重复读」这个保证） |
| 想「读-判断-写」原子 | 全程用当前读（`SELECT ... FOR UPDATE`）或乐观锁版本号，**不要**快照读判断后再写 |
| 事务里要固定快照点 | RR 下 `BEGIN` 后立刻发一句 `SELECT`，或用 `START TRANSACTION WITH CONSISTENT SNAPSHOT` |
| 线上 undo 暴涨 / 磁盘告警 | 查 `information_schema.INNODB_TRX` 按 `trx_started` 排序，杀掉最老的长事务；然后**等 purge 异步追**，别反复 kill |
| 监控 MVCC 健康度 | 盯 `History list length` 的**趋势**（不除以行数）；配 `innodb_max_purge_lag` 做保护 |
| 只想读历史快照做报表 | 别用长事务 RR 扛；用从库 / 快照备份 / 数仓，长事务会拖垮主库 undo |
| 判断读是否走了 MVCC | `SHOW ENGINE INNODB STATUS` 看 `N read views open inside InnoDB`（8.4 没有老的 `Trx read view` 行了） |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 版本链存在哪？ | undo log（回滚段）。所以「回滚」和「MVCC 读旧版本」用的是同一份数据，一份存储两个用途 |
| 一个版本什么时候才能被删？ | 没有比它更老的活跃 ReadView 时。InnoDB 用全局最老读视图驱动 purge |
| 为什么 RR 下 `UPDATE` 能看到别人刚提交的行？ | 因为 `UPDATE` 是当前读，走的是最新版本 + 加锁，不查 ReadView |
| RR 到底有没有幻读？ | 快照读下没有（靠 MVCC）；当前读下没有（靠 next-key 锁 = 记录锁 + 间隙锁）。只有「快照读和当前读混用」时才出现语义上的幻觉现象 |
| RC 为什么还要间隙锁？ | RC 不加间隙锁（除了外键和唯一键冲突检查），这正是它死锁少的原因 |
| ReadView 里 `max_trx_id` 为什么不等于最大事务号？ | 它是「下一个待分配的 id」。事务 id 是连续分配的，所以 `>= max_trx_id` 的一定是生成名单之后才开始的，必然不可见 |
| 长事务对主从延迟有影响吗？ | 有 —— 从库回放时同样要维护版本链，且大事务在从库是单线程回放的（见 Q23） |
| 为什么用 RC 时 `Rows_examined` 会更大？ | 半一致性读会让 RC 下的 `UPDATE` 先读最新版本再决定是否加锁，扫的行可能更多；这是 RC 换低死锁率的代价 |

---

## Q20. InnoDB 的锁机制是怎样的？死锁怎么排查和避免？

### 结论

InnoDB 的锁分三层，**面试答不清的就是第一层**：

1. **表级意向锁**：`IS` / `IX`，加行锁前自动加，作用是让「表锁」能快速判断有没有冲突（不用逐行扫）；
2. **行级锁**：`S`（共享）/ `X`（排他），加在**索引记录**上，不是加在「行」上；
3. **间隙锁**：`Gap Lock`，锁的是**索引记录之间的空隙**，只在 RR 及以上出现，为的是防幻读。

最关键的一句：**InnoDB 的行锁是加在「索引」上的，不是加在「行」上的。** 所以 `UPDATE` 没走索引 → 扫描到的每一条记录都加锁 → 实际等于锁全表。这是「没索引导致的锁表」的真正机理。

死锁本身不是错误，是 InnoDB 的**自我保护**（无环等待图检测，主动回滚代价小的一方）。应用层必须能重试 `1213`。要区分两个错误码：**`1213` 是死锁（重试即可），`1205` 是锁等待超时（说明有长事务，重试也没用，得先找那个事务）**。

### 一、锁的层次与兼容矩阵

```
                    表级：IS  IX  S   X         ← 意向锁只和「表锁」冲突
                          │
                          └── 行级（加在索引记录上）
                                 ├─ Record Lock     锁住这条索引记录
                                 ├─ Gap Lock        锁住记录之间的空隙（不含记录本身）
                                 └─ Next-Key Lock   = Record + 它前面的 Gap（RR 默认形态）
```

兼容矩阵（**实测**，`bench/mysql/15-locks.php`，用两个连接真并发验证）：

```
已持有 \ 想申请     S          X
S                 兼容      阻塞      ← 实测：S→S 耗时 0.00s，S→X 耗时 1.00s（超时）
X                 阻塞      阻塞      ← 实测：X→S 1.00s，X→X 1.00s
```

`X` 和任何锁都不兼容，`S` 只和 `S` 兼容。实测四个组合的耗时分别是 **0.00s / 1.00s / 1.00s / 1.00s**，和矩阵完全一致。

**加锁规则（RR 下，决定加 Record 还是 Next-Key）**：

| 情况 | 加什么锁 |
| --- | --- |
| 唯一索引 + 等值 + 记录存在 | Record Lock（退化成行锁，**不加** Gap） |
| 唯一索引 + 等值 + 记录不存在 | Gap Lock |
| 非唯一索引 + 等值 | Next-Key Lock（还要加上「下一条记录」的 Gap） |
| 范围查询 | Next-Key Lock（扫描到的每条都加） |
| 没走索引 | 扫到的每条记录都加 Next-Key Lock ≈ 锁全表 |

### 二、实测（MySQL 8.4.11）

**1）行锁等待的真实现场**（`performance_schema.data_locks` + `data_lock_waits` + `INNODB_TRX`）：

A 持有 `id=5` 的排他锁且未提交，B 来抢：

```
[A 持锁后] data_locks:
  trx=112638  PRIMARY  RECORD  X,REC_NOT_GAP  GRANTED  5
  trx=112638  -        TABLE   IX             GRANTED  -

[B 正在等] data_lock_waits:
  等待者 trx=112639  被阻塞者 trx=112638  mode=X,REC_NOT_GAP  data=5  status=WAITING

INNODB_TRX: trx_id=112639  state=LOCK WAIT  rows_locked=1  rows_modified=0
INNODB_TRX: trx_id=112638  state=RUNNING    rows_locked=1  rows_modified=1
```

这张「谁在等谁」的表是排查锁问题的第一现场：**`data_lock_waits` 直接给出了 blocking ↔ waiting 的配对**，`INNODB_TRX` 给出事务的开始时间和已改行数。`X,REC_NOT_GAP` 里的 `REC_NOT_GAP` 就是「只有记录锁，没有间隙锁」——因为 `id` 是主键（唯一索引等值命中），符合上面的加锁规则。

**2）RR 的间隙锁：锁的是「区间」不是「行」**（同一条 SQL，只改隔离级别）：

```
--- REPEATABLE READ ---
  SELECT * FROM lk WHERE id < 5 FOR UPDATE（只命中 1 行）：
    trx=112646  PRIMARY  RECORD  X,GAP       GRANTED  5     ← 空隙锁
    trx=112646  PRIMARY  RECORD  X           GRANTED  1     ← 记录锁
    trx=112646  -        TABLE   IX          GRANTED  -
  B 插入 id=3（不存在的行，落在空隙里）: 被挡，耗时 1.00s   ← 幻读被挡住了

--- READ COMMITTED ---
  SELECT * FROM lk WHERE id < 5 FOR UPDATE（同样只命中 1 行）：
    trx=112652  PRIMARY  RECORD  X,REC_NOT_GAP  GRANTED  1  ← 只有记录锁，没有 GAP
    trx=112652  -        TABLE   IX             GRANTED  -
  B 插入 id=3: 成功，耗时 0.00s                            ← RC 不防幻读
```

**这是「RC 死锁比 RR 少」的根本原因**：RC 不加间隙锁，锁的**范围**小得多。注意 `id < 5` 只命中了 1 行，但 RR 锁住的是「小于 5 的整个区间」——任何落在这个空隙里的插入都被挡住，哪怕它和现有数据毫无关系。

**3）同一条 SELECT，快照读和当前读的锁行为完全不同**：

```
普通 SELECT（快照读，走 MVCC）:
  data_locks: （无锁）

SELECT ... FOR UPDATE（当前读）:
  data_locks: trx=112658  PRIMARY  RECORD  X,REC_NOT_GAP  GRANTED  5
              trx=112658  -        TABLE   IX             GRANTED  -
```

这印证了 Q19 的结论：**MVCC 和锁是两条并行的路径**。同一句 `SELECT`，加不加 `FOR UPDATE` 就从「不加任何锁」变成「加排他锁」。

**4）SERIALIZABLE 把普通 SELECT 也变成了加锁读**（`bench/mysql/13-isolation.php`）：

```
SERIALIZABLE 下的普通 SELECT：
  iso          TABLE    IS                GRANTED  -
  iso          PRIMARY  RECORD  S,REC_NOT_GAP  GRANTED  1   ← 普通读也加 S 锁

对照 REPEATABLE READ 下同样的普通 SELECT：
  data_locks 里 iso 的锁数量: 0
```

**5）死锁必须真并发才能复现**（`bench/mysql/05-lock.php`，用 `pcntl_fork`）：

```
[A] 已持有 id=1，等 0.4s 让 B 先拿到 id=2
[B] 已持有 id=2，等 0.4s 让 A 先拿到 id=1
[A] 被回滚：SQLSTATE=40001 errno=1213 → DEADLOCK（InnoDB 主动挑一个牺牲者回滚）
```

错误码是 **`1213` + SQLSTATE `40001`**。`SHOW ENGINE INNODB STATUS` 里的现场（`LATEST DETECTED DEADLOCK` 段）：

```
*** (1) TRANSACTION:
TRANSACTION 119645, ACTIVE 0 sec starting index read
MySQL thread id 12864, ... updating
*** (1) HOLDS THE LOCK(S):
RECORD LOCKS ... index PRIMARY of table `learn`.`stock` trx id 119645 lock_mode X locks rec but not gap
*** (1) WAITING FOR THIS LOCK TO BE GRANTED:
RECORD LOCKS ... index PRIMARY of table `learn`.`stock` trx id 119645 lock_mode X locks rec but not gap waiting
*** (2) TRANSACTION:
TRANSACTION 119646, ACTIVE 0 sec starting index read
...
*** (2) HOLDS THE LOCK(S):
RECORD LOCKS ... trx id 119646 lock_mode X locks rec but not gap
*** (2) WAITING FOR THIS LOCK TO BE GRANTED:
RECORD LOCKS ... trx id 119646 lock_mode X locks rec but not gap waiting
```

读法就一句：**每个事务都「HOLDS 一个」+「WAITING 一个」，两个 WAITING 正好指向对方 HOLDS 的那个 → 成环 → 死锁。**

**6）锁等待超时 vs 死锁是两个不同的东西**（`05-lock.php` 第 1 节）：

```
A 已锁住 id=1
B 被阻塞 3.0s 后失败: SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded
A 锁 id=1 时，B 改 id=2 耗时 0.004s（不同行不冲突）
```

**`1205` 是「等太久了主动放弃」，`1213` 是「被检测出环后主动回滚」**。上面这个场景里 B 等的是一个**永远不会释放**的锁（A 没提交），不成环，所以只能是 1205。另外注意最后一行：改**不同行**不冲突，耗时 0.004s——行锁的粒度确实是行。

**7）MySQL 8.4 没有全局死锁计数器**（实测）：

```
SHOW GLOBAL STATUS LIKE '%deadlock%'   → 返回 0 行
Innodb_row_lock_waits 累计 = 14973     → 含死锁和普通锁等待超时，不区分
```

所以死锁次数**没法从 `SHOW GLOBAL STATUS` 里读**，只能靠 `innodb_print_all_deadlocks=ON` 把每次死锁写进错误日志，再去数。这是排查死锁必须先开的一个开关（默认是 OFF，只保留「最近一次」在 `INNODB STATUS` 里）。

**8）全局锁指标**（`SHOW GLOBAL STATUS`）：

```
Innodb_row_lock_current_waits   0          ← 此刻正在等锁的事务数
Innodb_row_lock_time            7441889    ← 累计等锁毫秒
Innodb_row_lock_time_avg        538        ← 平均等锁毫秒
Innodb_row_lock_time_max        51256      ← 最长一次等锁毫秒（51 秒！）
Innodb_row_lock_waits           13827      ← 累计等锁次数
```

`time_max = 51256 ms` 这个数字很说明问题：**有一次锁等待等了 51 秒**。默认 `innodb_lock_wait_timeout` 是 50 秒，所以这就是一个「等满超时」的记录。这类指标要**看趋势**，绝对值受实例历史影响。

### 三、四个反直觉的点

**1. 死锁不是「bug」，是 InnoDB 主动做的选择。**

InnoDB 检测到等待成环后，会挑一个**代价最小**的事务（通常是修改行数少的那个）回滚，报 `1213` 给客户端。不做这件事的后果是**所有**相关事务一起卡死到超时。

所以：
- 应用层**必须**能重试 `1213`（而不是把它当成「系统坏了」抛给用户）；
- 重试要加**退避 + 次数上限**，否则两个事务可能反复撞死；
- 反过来，`1205` 重试是没用的——它是「有人一直不提交」，重试还会超时，得先去揪出那个长事务。

**2. 「没走索引的 UPDATE」等于锁全表，而且比全表扫更糟。**

`UPDATE t SET v=1 WHERE name='x'` 如果 `name` 没索引：InnoDB 只能全表扫描，**扫到的每一行都加 Next-Key Lock**。这不是「锁了这些行」，而是「锁了这些行 + 它们之间的所有空隙」——别的会话连插入都插不进来。

危险的地方在于它**看起来只是慢**。执行计划显示 `type=ALL`，你能看到「慢」，但看不到「锁了多少」。所以线上 `UPDATE` / `DELETE` 前一定要先 `EXPLAIN` 确认走了索引。

**3. 唯一索引等值命中会「退化」成 Record Lock，不加间隙锁；但等值**没命中**反而加 Gap Lock。**

因为「记录存在」时锁住这一条就够了（唯一性保证不会再有第二条）；而「记录不存在」时，要防止别人插进来，所以锁的是那个空隙。这个不对称很容易记反：

```
WHERE id = 5  且 id=5 存在  →  X,REC_NOT_GAP   （实测看到的就是这个）
WHERE id = 5  且 id=5 不存在 →  X,GAP
```

**4. `SELECT ... FOR UPDATE` 在 RC 下比 RR 下「锁得少」，但也更不安全。**

RC 下没有间隙锁，所以并发插入不会被挡（实测 `B 插入 id=3: 成功，耗时 0.00s`）。如果你的业务逻辑依赖「锁住一个范围，别人插不进来」，**改成 RC 会让这个假设失效**——它不会报错，只会在并发下悄悄出现幻读。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 排查「谁在等锁」 | `performance_schema.data_lock_waits`（配对关系）+ `information_schema.INNODB_TRX`（按 `trx_started` 找最老的） |
| 排查死锁 | `innodb_print_all_deadlocks=ON`（默认 OFF！）+ 错误日志；`INNODB STATUS` 只看得到最近一次 |
| 收到 `1213` | 应用层**重试 + 退避**，这是正常现象 |
| 收到 `1205` | 别重试；去找那个不提交的长事务（`INNODB_TRX` 按 `trx_started` 排序） |
| 减少死锁 | 固定加锁顺序（按主键排序后再批量更新）、缩短事务、`UPDATE`/`DELETE` 必须走索引 |
| 高并发写入且死锁频繁 | 评估 RC（去掉间隙锁）；但要确认业务不依赖「范围锁」 |
| `UPDATE` / `DELETE` 上线前 | 必须 `EXPLAIN` 确认走索引，否则锁范围会失控 |
| 批量更新 | 拆成小批 + 按主键 `ORDER BY` 排序，保证所有会话的加锁顺序一致 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么要有意向锁？ | 加行锁前先加 `IX`/`IS`，这样「加表锁」时只需检查一个标志位，不用逐行扫有没有行锁 |
| 意向锁之间会冲突吗？ | 不会。`IX` 和 `IX` 兼容，它们只和表级的 `S`/`X` 冲突 |
| Gap Lock 会因为什么失效？ | 改成 RC（不加）、或者 SQL 走了唯一索引等值命中（退化成 Record Lock） |
| 死锁能彻底避免吗？ | 不能。只能减少概率 + 应用层能重试。固定加锁顺序能消除绝大部分「相反顺序」型死锁 |
| InnoDB 怎么检测死锁？ | 等待图（wait-for graph）深度优先搜环；`innodb_deadlock_detect=ON` 时是即时检测。关了它就只能靠 `innodb_lock_wait_timeout` 兜底 |
| 关掉死锁检测会更快吗？ | 高并发下检测本身有开销，超大并发场景有人会关（换吞吐）；但代价是死锁只能靠超时解开，延迟从毫秒变 50 秒 |
| 插入意向锁是什么？ | `Insert Intention Lock` 是一种特殊的 Gap Lock，多个事务往**同一个空隙的不同位置**插入时互相不冲突（比如都往 (5,10) 里插，插 6 和插 8 不冲突），这是为了提升并发插入 |
| 自增锁是什么？ | `AUTO-INC` 表级锁。8.0 起默认 `innodb_autoinc_lock_mode=2`（交错模式），批量插入不再阻塞，代价是自增值可能不连续 |
| 一条 `UPDATE` 改 10 行会加几个锁？ | 走唯一索引：10 个 Record Lock；走普通索引：10 个 Next-Key Lock **加上**相邻空隙；没走索引：可能上千个 |
| 锁信息为什么在 `performance_schema` 而不在 `INFORMATION_SCHEMA`？ | 8.0 起锁信息搬到了 `performance_schema.data_locks` / `data_lock_waits`，老版本要在 `INNODB_LOCKS` / `INNODB_LOCK_WAITS` 里查（8.0 已废弃） |

---

## Q21. 线上发现慢 SQL，你的排查思路是什么？

### 结论

排查顺序只有一条主线：**「谁慢」→「慢在哪」→「为什么慢」→「改了什么」→「真的好了吗」**。

```
① 发现     慢日志 / digest 聚合 / APM 告警 / 用户投诉
              ↓  拿到「具体是哪条 SQL 模式」
② 排序     performance_schema.events_statements_summary_by_digest
              按 SUM_TIMER_WAIT 排 → 挑 TopN（比翻慢日志快，且是「累计代价」视角）
              ↓
③ 看计划   EXPLAIN          → 优化器选了什么（type / key / rows / Extra）
④ 看真实   EXPLAIN ANALYZE  → 真实行数 + 每一步花了多少毫秒（+ 可强制 NO_INDEX 做对照）
              ↓
⑤ 定量     看 Rows_examined ÷ Rows_sent（放大倍数）
              放大倍数 >> 1 → 「看得多、取得少」→ 索引问题
              放大倍数 ≈ 1 且慢 → 「取得多」→ 结果集本身太大 / 网络传输问题
              ↓
⑥ 改      加索引 / 改写 SQL / 改表结构
              ↓
⑦ 复测    用同一条 SQL 再跑，**回慢日志里对 Query_time**（而不是靠感觉）
```

一句话总结判据：**`Rows_examined / Rows_sent` 是排查慢 SQL 最重要的一个比值。**

### 一、这条链路需要哪些东西先就位

| 组件 | 本次实测的值 | 作用 |
| --- | --- | --- |
| `slow_query_log` | `ON` | 前提 |
| `long_query_time` | **0.1**（默认是 10s，太粗） | 门槛。生产建议 0.1~0.5 |
| `log_slow_extra` | `ON`（8.0.14+，脚本里临时打开） | 带出 `Read_rnd_next` / `Sort_scan_count` / `Rows_affected` / `Start-End` 等现场 |
| 慢日志文件 | `/var/lib/mysql/slow.log` | 落盘位置 |
| `events_statements_summary_by_digest` | 默认就在 | 不用自己 parse 日志的聚合视角 |
| `EXPLAIN ANALYZE` | 8.0.18+ | 给**真实**行数和耗时，不是估算 |

**踩坑**：`mysqldumpslow` 是 Perl 脚本，**Oracle 官方的 `mysql:8.4` 镜像里没带**（实测 `which mysqldumpslow` 找不到）。替代品就是 `events_statements_summary_by_digest` 表，或者自己用 awk 聚合（本脚本两种都演示了）。

### 二、实测（MySQL 8.4.11）

**1）造 4 类典型的慢 SQL，看慢日志记下了什么**（`bench/mysql/17-slow-sql.sh`，`users` 表 498,653 行）：

| 标记 | SQL | `Query_time` | `Rows_sent` | `Rows_examined` | 放大倍数 | 关键字段 |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| (a) | `COUNT(*) WHERE city='北京' AND age>20` | **未进慢日志** | 94,000 | — | — | 63.9 ms，低于 100 ms 阈值 |
| (b) | `SELECT * WHERE email LIKE '%12345@example.com'` | 0.438343 | 5 | 500,000 | **100,000x** | `Read_rnd_next=500001` |
| (c) | `SELECT * ORDER BY name LIMIT 10` | 0.265622 | 10 | 500,010 | **50,001x** | `Read_rnd_next=500001`、`Sort_scan_count=1`、`Sort_rows=10` |
| (d) | `COUNT(*) WHERE created_at > '2026-01-01'` | 0.150780 | 1 | 360,309 | **360,309x** | `Read_next=360309`（**走了索引**） |
| (d') | 同上，加 `(created_at,city,age)` 覆盖索引后 | 0.100257 | 1 | 360,309 | 360,309x | `Read_next=360309`（扫描量没变） |

**注意 (a) 那一行：它压根没进慢日志。** 63.9 ms 虽然比 (d) 的 100 ms 更快，但它是「每次都要扫 19 万行索引」的高频查询，累计代价可能更大。**只看慢日志会漏掉这类「中等耗时 × 高频」的问题** —— 这是必须看 digest 聚合的原因。

**2）慢日志原始条目长什么样**（`log_slow_extra=ON`，以 (c) 为例）：

```
# Time: 2026-09-20T05:48:36.912828Z
# User@Host: root[root] @ localhost []  Id: 12444
# Query_time: 0.265622  Lock_time: 0.000001 Rows_sent: 10  Rows_examined: 500010 Thread_id: 12444 Errno: 0 Killed: 0 Bytes_received: 64 Bytes_sent: 993 Read_first: 1 Read_last: 0 Read_key: 1 Read_next: 0 Read_prev: 0 Read_rnd: 0 Read_rnd_next: 500001 Sort_merge_passes: 0 Sort_range_count: 0 Sort_rows: 10 Sort_scan_count: 1 Created_tmp_disk_tables: 0 Created_tmp_tables: 0 Start: 2026-09-20T05:48:36.647206Z End: 2026-09-20T05:48:36.912828Z
SET timestamp=1789883316;
SELECT /*q21probe_c*/ * FROM users ORDER BY name LIMIT 10;
```

这一行就能读出完整结论：

| 字段 | 值 | 说明 |
| --- | --- | --- |
| `Rows_sent` / `Rows_examined` | 10 / 500,010 | 看了 50 万行只返回 10 行 |
| `Read_rnd_next` | 500,001 | **全表扫描**的铁证（`rnd_next` = 无序读下一行） |
| `Sort_scan_count` | 1 | 做了一次**文件排序** |
| `Sort_rows` | 10 | 只保留了 10 行 —— `ORDER BY ... LIMIT 10` 用了**有界优先队列**，不是全量排序 |
| `Start` / `End` | 相差 265 ms | `Query_time` 的真实构成（含等锁） |
| `Lock_time` | 0.000001 | 几乎没等锁 → 不是锁竞争问题 |

**3）用 `EXPLAIN ANALYZE` 拿到真实代价（以 (a) 为例，做对照实验）**：

优化器自己选的（走覆盖索引）：

```
-> Aggregate: count(0)  (cost=59876 rows=1) (actual time=63.9..63.9 rows=1 loops=1)
    -> Filter: ((users.city = '北京') and (users.age > 20))  (cost=40774 rows=191020) (actual time=0.172..58.2 rows=94000 loops=1)
        -> Covering index range scan on users using idx_city_age over (city = '北京' AND 20 < age)
             (cost=40774 rows=191020) (actual time=0.169..36.7 rows=94000 loops=1)
```

强制全表扫（`/*+ NO_INDEX(users) */`）：

```
-> Aggregate: count(0)  (cost=54573 rows=1) (actual time=278..278 rows=1 loops=1)
    -> Filter: ((users.city = '北京') and (users.age > 20))  (cost=50418 rows=41550) (actual time=0.0805..271 rows=94000 loops=1)
        -> Table scan on users  (cost=50418 rows=498653) (actual time=0.0755..167 rows=500000 loops=1)
```

**278 ms vs 63.9 ms ≈ 4.3 倍**，优化器的选择是对的。这个「用 `NO_INDEX` 强制走另一条路做对照」的手法是排查时最有用的一招：**能直接量出「优化器有没有选错」，而不是靠猜。**

另外注意 `cost=50418 rows=41550` 那个**估算**：优化器估计过滤后剩 41,550 行，**实际是 94,000 行**——估偏了 2.3 倍。基数估算不准正是优化器选错索引的头号原因。

**4）聚合视角：`events_statements_summary_by_digest`**（不用 parse 日志，直接按累计耗时排序）：

```
stmt                                                 次数      累计ms   平均ms   扫描行     返回行     放大倍数  没走索引
SELECT NAME FROM `bt` WHERE `id` = ?                 1912022  323337.2    0.17  1792021   1792021         1        0
SELECT `pad` FROM `tiny` WHERE `id` = ?               531002   90205.9    0.17   531002    531002         1        0
INSERT INTO `bt` (NAME, `age`, ...)                       22   84937.3 3860.78  4000000         0   4000000       22
SELECT NAME FROM `users` WHERE `id` = ?               390009   67133.1    0.17   390009    390009         1        0
UPDATE `iso` SET `v` = ? WHERE `id` = ?                   34   58026.9 1706.67       29         0        29        0
UPDATE `stock` SET `qty` = `qty` - ? WHERE `id` = ?        11   53059.4 4823.57        8         0         8        0
SELECT SUM(`LENGTH`(`d`)) FROM `t_abc` WHERE ...         645   28823.3   44.69  6247142       645      9685        0
```

这张表的价值在于**它按「累计耗时」排序，而不是按「单次耗时」**。看第一行：`WHERE id = ?` 单次只要 0.17 ms，但因为跑了 **191 万次**，累计 323 秒，是全实例第一大开销。**这类问题在慢日志里一条都看不到**（每条都低于阈值），只有 digest 能发现。

反过来 `INSERT INTO bt` 只跑了 22 次，但单次 3.86 秒、放大倍数 400 万，累计 84 秒。

> 单位坑：`SUM_TIMER_WAIT` / `AVG_TIMER_WAIT` 的单位是**皮秒**，换算成毫秒要 `/1e9`。我第一版脚本写成 `/1e6`，结果「平均 0.17 ms」显示成了「169.11 ms」——**大了 1000 倍**。这种错在报告里非常容易被当成「数据库很慢」而误判。

**5）优化前后用慢日志复测**（(d) 那条）：

```
优化前：Query_time: 0.150780  Rows_examined: 360309  Read_next: 360309
优化后：Query_time: 0.100257  Rows_examined: 360309  Read_next: 360309
        （加了 (created_at, city, age) 覆盖索引）
```

**只快了 1.5 倍，而且 `Rows_examined` 一行都没少。** 因为这条 SQL 的问题是「36 万行都满足 `created_at > '2026-01-01'`」——**选择性太差**，加索引只能让它从「回表扫」变成「覆盖索引扫」，省掉回表但省不掉扫描。

**这就是「有索引≠快」的实测证据**：如果一条 SQL 的 `Rows_examined/Rows_sent` 比值很大，但加了索引之后 `Rows_examined` 没降，说明问题不在索引缺失，而在**谓词的选择性**——只能改业务（加更严的条件、分页、预聚合）。

### 三、四个反直觉的点

**1. 慢日志的阈值是「绝对时间」，所以它抓不到最贵的问题。**

上面 digest 的第一名 `SELECT NAME FROM bt WHERE id=?` 单次 0.17 ms，永远不会进慢日志，但累计 323 秒排第一。

**「高频 × 中等耗时」和「低频 × 极慢」都要看**，前者靠 digest，后者靠慢日志。只看一边都会漏。

**2. `EXPLAIN` 的 `rows` 是估算，可能偏好几倍。**

上面 `cost=50418 rows=41550` vs 实际 94,000 行，偏了 2.3 倍。**`EXPLAIN` 不能告诉你「实际扫了多少行」**，要 `EXPLAIN ANALYZE`（8.0.18+）或看慢日志的 `Rows_examined`。

排查时如果发现「优化器选的索引看起来不对」，第一步就该用 `NO_INDEX` / `FORCE INDEX` 做 A/B 对照，而不是直接下结论。

**3. `Sort_rows=10` 不代表「只排了 10 行」。**

它是「排序后保留的行数」。`ORDER BY name LIMIT 10` 在 50 万行上做 filesort，MySQL 用**有界优先队列**（堆）只保留前 10，所以 `Sort_rows=10`；但 `Rows_examined=500010`、`Read_rnd_next=500001` 说明**它还是把 50 万行全读了一遍**。

看排序成本要看 `Sort_scan_count` + `Rows_examined`，不是 `Sort_rows`。

**4. `Lock_time` 小不代表没有并发问题。**

`Lock_time` 只统计**等表锁/元数据锁**的时间，**不包括 InnoDB 行锁等待**。行锁等待会算在 `Query_time` 里但 `Lock_time` 依然接近 0（实测 0.000001）。

所以「`Query_time` 大但 `Lock_time` 小」不能推出「没有锁问题」。要确认行锁得看 `Innodb_row_lock_*` 指标或 `data_lock_waits`（见 Q20）。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 先开什么 | `slow_query_log=ON` + `long_query_time=0.1`（不是默认 10）+ `log_slow_extra=ON` |
| 按什么排序 | 先看 digest 的 `SUM_TIMER_WAIT`（累计代价），再看慢日志的 `Query_time`（单次极值） |
| 第一判据 | `Rows_examined / Rows_sent`。远大于 1 → 索引问题；接近 1 还慢 → 结果集太大 |
| 看计划 | `EXPLAIN` 看选了哪条路；`EXPLAIN ANALYZE` 看真实行数和每步耗时 |
| 怀疑优化器选错 | 用 `NO_INDEX` / `FORCE INDEX` 做 A/B，量出差距再下结论 |
| 加了索引还是慢 | 对比 `Rows_examined` 有没有下降；没降就是**选择性**问题，不是索引问题 |
| 排序慢 | 看 `Sort_scan_count`；能让索引本身有序就别 filesort（见 Q15） |
| 复测 | **回慢日志对 `Query_time`**，不要凭感觉；同一实例的负载会变，最好固定条件重测 |
| 单位 | `performance_schema` 的 timer 全是**皮秒**，`/1e9` 才是毫秒 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| `long_query_time` 设多少？ | 生产 0.1~0.5s。设 0 会记录**所有**查询，日志爆炸；设默认 10s 几乎什么都抓不到 |
| `Rows_examined` 和 `Rows_sent` 差多少算异常？ | 没有绝对阈值。看趋势和 TopN；单条查询放大 10 万倍一定是问题 |
| 慢日志会影响性能吗？ | 会（写磁盘 + `log_slow_extra` 的额外统计），但远小于「不排查」的代价。高 QPS 实例建议写独立盘或用 `log_output=TABLE` |
| `mysqldumpslow` 为什么没有？ | 它是 Perl 脚本，官方精简镜像不装。用 digest 表或 awk 替代 |
| `pt-query-digest` 呢？ | Percona Toolkit 的，功能强得多（能按「响应时间占比」聚合，还能分析 tcpdump）。生产值得装 |
| `EXPLAIN ANALYZE` 会真的执行 SQL 吗？ | 会。所以**不要**对 `UPDATE` / `DELETE` 直接跑；8.0.32+ 有 `EXPLAIN ANALYZE` 对 DML 的保护（会回滚），但早期版本会真改数据 |
| digest 表怎么清？ | `TRUNCATE performance_schema.events_statements_summary_by_digest`，或 `CALL sys.ps_truncate_all_tables(FALSE)` |
| digest 归一化有什么坑？ | `WHERE id = ?` 把不同参数合并了，所以**看不出「只有某个参数慢」**（比如某个用户的数据特别多）。要定位到参数就得回慢日志 |
| 优化器为什么估错行数？ | 基数估算基于索引统计（采样），且多列之间有相关性时误差大（本例 `city` 和 `age` 相关）。可 `ANALYZE TABLE` 刷新统计，或建直方图（8.0 `ANALYZE TABLE ... UPDATE HISTOGRAM`） |
| 排查完发现是「表太大」怎么办？ | 那就是 Q24 的问题了：分区 / 归档 / 分片。**先确认不是索引和 SQL 的问题，再动表结构** |

---

## Q22. 深分页为什么慢？怎么优化？

### 结论

`LIMIT offset, N` 慢的根因只有一句话：**MySQL 没有办法「跳过」前 offset 行，它只能一行一行地读出这 offset 行，然后全部丢掉。**

这个代价和 `offset` **严格成正比**，跟你要的那 20 行毫无关系。实测（`pg` 表 50 万行）：

| 查询 | 耗时 | `Handler_read_next` |
| --- | ---: | ---: |
| `LIMIT 0, 20` | 0.65 ms | 19 |
| `LIMIT 1000, 20` | 0.78 ms | 1,019 |
| `LIMIT 100000, 20` | 59.84 ms | 100,019 |
| `LIMIT 400000, 20` | **239.42 ms** | **400,019** |
| `LIMIT 499000, 20` | 232.68 ms | 499,019 |

**返回的行数永远是 20，读的行数从 19 涨到 499,019 —— 涨了 2.6 万倍。** 三种改写的效果：

| 改写 | 400000 偏移处耗时 | 相对原版 |
| --- | ---: | ---: |
| 原版 `LIMIT 400000, 20` | 186.61 ms | 基准 |
| 延迟关联（先取主键再 join 回表） | **135.62 ms** | 1.38x |
| 游标 / 书签（`WHERE id > ?`） | **0.53 ms** | **约 440x** |

**只有游标法是数量级的改善**，因为它把「扫描量」从 O(offset) 变成了 O(1)。延迟关联只是把「回表」省掉，扫描量一点没少，所以只有 1.4 倍。

### 一、为什么只能线性扫：执行计划里根本没有「跳过 N 行」这个算子

```
原版（EXPLAIN ANALYZE）：
  -> Limit/Offset: 20/400000 row(s)  (cost=34068 rows=20) (actual time=205..205 rows=20 loops=1)
      -> Index scan on pg using PRIMARY  (cost=34068 rows=400020) (actual time=0.072..181 rows=400020 loops=1)
                                         ↑ 注意这一行：为了给出 20 行，它扫了 400020 行
```

`Limit/Offset` 这个算子的语义是「**丢弃前 400000 行，返回接下来的 20 行**」——丢弃这个动作本身就要先把行读出来。所以 `Index scan` 必须真的产出 400,020 行，`LIMIT` 才拿得到它要的 20 行。

对比游标法：

```
SELECT * FROM pg WHERE id > 400000 ORDER BY id LIMIT 20
  → 0.53 ms，Handler_read_next = 19      ← 直接定位到 id>400000 的第一行，只读 19 行
```

因为 `WHERE id > 400000` **是索引能直接定位的起点**，B+Tree 一次查找就跳到那里了。`LIMIT` 缺少的正是这个「定位起点」的能力——**它只有「从头开始」和「丢 N 行」两个动作**。

### 二、实测（MySQL 8.4.11）

表结构：`pg` 50 万行，`pad VARCHAR(255)` 用 `REPEAT('x',200)` 填充（模拟宽表），`idx_created (created_at)`。

```
pg 表 500000 行：聚簇索引 121 MB，二级索引 idx_created 20 MB
```

**1）代价随 offset 线性增长**（`bench/mysql/18-pagination.php`）：

| 查询 | 耗时 | 返回行 | `read_key` | `read_next` | `read_rnd_next` |
| --- | ---: | ---: | ---: | ---: | ---: |
| `ORDER BY id LIMIT 0, 20` | 0.65 ms | 20 | 1 | 19 | 0 |
| `ORDER BY id LIMIT 1000, 20` | 0.78 ms | 20 | 1 | 1,019 | 0 |
| `ORDER BY id LIMIT 100000, 20` | 59.84 ms | 20 | 1 | 100,019 | 0 |
| `ORDER BY id LIMIT 400000, 20` | 239.42 ms | 20 | 1 | 400,019 | 0 |
| `ORDER BY id LIMIT 499000, 20` | 232.68 ms | 20 | 1 | 499,019 | 0 |

**`read_next` 精确等于 `offset + 19`** —— 这就是「一行一行读出来再丢掉」无可辩驳的证据。

**2）改写一：延迟关联**（`SELECT *` 改成先只取主键，再 join 回原表）：

| offset | 原版 | 延迟关联 | 加速比 | 延迟关联的 `read_key` / `read_rnd_next` |
| ---: | ---: | ---: | ---: | --- |
| 100,000 | 40.48 ms | 35.73 ms | 1.13x | 21 / 21 |
| 400,000 | 186.61 ms | 135.62 ms | 1.38x | 21 / 21 |

注意 `read_next` **还是 400,019**（扫描量没变），变的是它现在扫的是「只有主键的覆盖索引」而不是「带 200 字节 pad 的整行」，最后只回表 20 次（`read_key=21`）。

```
延迟关联版的执行计划：
  -> Nested loop inner join  (cost=134080 rows=20) (actual time=187..187 rows=20 loops=1)
      -> Table scan on t  (actual time=186..186 rows=20 loops=1)
          -> Materialize  (actual time=186..186 rows=20)
              -> Limit/Offset: 20/400000 row(s)  (actual time=186..186 rows=20 loops=1)
                  -> Covering index scan on pg using PRIMARY  (actual time=6.32..155 rows=400020 loops=1)
                                                                 ↑ 覆盖索引扫，不回表
      -> Single-row index lookup on p using PRIMARY (id=t.id)  (actual time=0.00347..0.00351 rows=1 loops=20)
                                                                 ↑ 只回表 20 次
```

**3）改写二：游标 / 书签**（记住上一页最后一行的 id）：

```
WHERE id > 400000 ORDER BY id LIMIT 20     0.53 ms    read_next = 19
```

**和原版 232 ms 差了约 440 倍**，而且 `read_next` 是 19 而不是 400,019。

**4）改写三：`ORDER BY` 一个「有索引但用不上」的列，退化得更彻底**：

| 查询 | 耗时 | `read_next` | `read_rnd_next` |
| --- | ---: | ---: | ---: |
| `ORDER BY created_at LIMIT 0, 20` | 0.71 ms | 19 | 0 |
| `ORDER BY created_at LIMIT 400000, 20` | **1294.05 ms** | **0** | **500,001** |

**这才是最深分页最糟的形态**：`created_at` 上虽然有 `idx_created`，但因为要 `SELECT *`（拿到 200 字节的 pad），优化器选择**全表扫描 + 文件排序**，`read_rnd_next=500001` 就是「扫了整张表 50 万行」的铁证。**1.29 秒，比走主键的 232 ms 还慢 5.5 倍。**

### 三、四个反直觉的点

**1. 给 `ORDER BY` 的列加索引，未必能救深分页。**

上面第 4 组就是例子：`created_at` 有索引，但执行计划是 `read_rnd_next=500001`（全表扫 + 排序），因为 `SELECT *` 要的列索引里没有，优化器算下来觉得「全表扫 + 排序」比「走二级索引 + 40 万次回表」更便宜。

**所以「加索引」在这里是无效动作**——真正的问题是 `LIMIT offset, N` 这个句式。

**2. 延迟关联的收益被高估了。**

网上常说「延迟关联能大幅提升深分页性能」，但本次实测只快了 **1.13x ~ 1.38x**。原因很直接：**它省的是回表，没省扫描。**

- 如果表很宽（本次 pad 200 字节），省下的回表 IO 相对可观 → 1.4x；
- 如果表本来就窄，省下的没多少 → 接近 1x；
- **扫描量始终是 O(offset)**，offset 再大还是慢。

延迟关联真正的价值场景是「**必须要 `SELECT *` 且必须支持跳页**」——在跳页这个约束下它是最优解。如果能改成游标，就该直接改游标。

**3. 游标法的限制不是「性能」，是「产品形态」。**

`WHERE id > ?` 要求：
- **不能跳页**（只能上一页/下一页，因为每一页都依赖上一页的最后一个 id）；
- 排序键必须**唯一且稳定**——用 `created_at` 排序时时间相同的行顺序不定，会导致漏行/重复行，必须用 `(created_at, id)` 这样的组合键兜底。

所以面试里正确的回答不是「用游标啊」，而是「**看产品需不需要跳页**：需要 → 延迟关联 / 接受深分页的代价；不需要 → 游标法，并且排序键要补唯一列」。

**4. `LIMIT 499000, 20` 比 `LIMIT 400000, 20` 还快（232.68 vs 239.42 ms）。**

差得不多，但这说明**耗时不是严格线性的**——除了扫描行数，还有 buffer pool 命中率、预读、以及本环境上的并发干扰。所以这些数字应该看**量级和趋势**（offset 涨 10 倍，耗时涨约 10 倍），别把单点数字当基准。

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 后台列表、必须支持跳页 | 延迟关联（先取主键再 join）+ 强制走覆盖索引；同时**限制最大页数** |
| 信息流 / 滚动加载 | **游标法**（`WHERE (created_at, id) < (?, ?)`），排序键补唯一列 |
| 导出全量数据 | 不要用大 `LIMIT` 循环翻页；用游标法按主键分批，或直接用从库/数仓 |
| `COUNT(*)` 总页数 | 深分页页面上不要实时算；用近似值、缓存、或「最多显示前 N 页」 |
| 判断是不是深分页问题 | `Handler_read_next` ≈ `offset + N`；或 EXPLAIN 里 `Limit/Offset: N/offset` |
| 为什么不能靠加索引解决 | 索引能让「定位起点」变快，但 `LIMIT` 没有定位能力，它只能从头丢 |
| 唯一正确的兜底原则 | **能不用 `OFFSET` 就不用**；`OFFSET` 是「让我把不要的数据也读一遍」的正式说法 |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 为什么设计成不能跳过？ | B+Tree 记录的是「第几行」的位置而不是「第几行」的编号；要知道第 400000 行在哪，只能从头数 |
| 游标法怎么处理「上一页」？ | 反过来查：`WHERE id < ? ORDER BY id DESC LIMIT 20`，再把结果倒序 |
| 游标法排序键不唯一怎么办？ | 用 `(排序键, 主键)` 组合做游标，保证全序；`WHERE (created_at, id) < (?, ?)` |
| 延迟关联一定要用 JOIN 吗？ | 也可以分两步：先 `SELECT id ... LIMIT offset,20` 拿到 20 个 id，再 `WHERE id IN (...)`。效果一样，少一层嵌套 |
| 子查询里为什么能走覆盖索引？ | 因为子查询只 `SELECT id`，`PRIMARY` 索引的叶子本身就有 id，不用回表 → `Covering index scan` |
| `LIMIT` 大 offset 会锁很多行吗？ | 普通 `SELECT` 是快照读，不加锁；但如果是 `SELECT ... FOR UPDATE` 深分页，会锁住扫过的**全部**行，非常危险 |
| MySQL 8.0 的窗口函数能优化深分页吗？ | 不能。窗口函数本身也要扫全表才能算出行号，反而更慢 |
| 主键是 UUID 时游标法还能用吗？ | 能，但 UUID 无序会导致游标定位退化成扫描；用「时间有序」的 ID（雪花/ULID）才有效 |

---

## Q23. 主从延迟的原因与治理？读写分离下如何保证读到最新数据？

### 结论

主从延迟的根因不是「网络慢」，而是**主库是 N 个并发事务在提交，从库默认只有 1 个 SQL 线程在串行回放**。延迟的本质是**排队积压**，不是「同步慢一点」：

```
延迟 ≈ 队列里积压的事务数 ÷ 回放速率
```

实测（`learn-mysql` 8.4.11 当主、`q23-slave` 8.4.11 当从，`binlog_format=ROW`，主从同宿主）：

| 场景 | 实测结果 |
| --- | ---: |
| 单事务 `UPDATE` 50 万行 | 从库 `Seconds_Behind_Source` 峰值 **8s**，追平耗时 3059ms |
| 2000 个互不冲突的小事务，`replica_parallel_workers=0` | 回放到同一位点 **6379ms**（≈313 TPS） |
| 同上，`replica_parallel_workers=4` | 回放到同一位点 **1873ms**（≈1068 TPS）→ **3.4x** |
| 写完主库**立刻**按主键读从库，500 轮 | **500/500 = 100% 读到旧数据**；等到可见 p50 4.54ms、p90 5.62ms、max 67.33ms |
| 同一条主键点查 ×2000 | 主库 0.232ms/次，从库 0.225ms/次（**几乎没有差别**） |

两个结论直接从这个表里出来：

1. **要治的是回放吞吐**（并行回放 / 大事务拆小 / 从库别跑重查询），不是去优化网络；
2. **读写分离并不能让单条查询变快**（0.232 vs 0.225ms），它买到的是「把并发读的压力从主库挪走」，代价是**写后立刻读从库 100% 读到旧值**，必须自己解决。

### 一、延迟产生在哪一段：三段管道，只有一个串行瓶颈

```
        主库                                          从库
┌──────────────────┐            ┌───────────────┐          ┌─────────────┐
│  N 个并发事务提交  │  binlog    │ dump 线程      │ relay    │  SQL 线程    │
│  (写是并行的)     │ ─────────► │ (=IO 线程)     │ ───────► │  (默认 1 个) │ ──► 数据
└──────────────────┘  ① 网络    └───────────────┘  ③ 落盘   └─────────────┘
                     ② 只读+转发                            ④ 串行回放
                                                                 ↑ 唯一的瓶颈
```

| 环节 | 并行度 | 会不会成为瓶颈 |
| --- | --- | --- |
| 主库提交 | N 个会话并发 | 不是瓶颈，但它正是「积压」的来源 |
| dump / IO 线程 | 1 个，只做「读 binlog + 发网络」 | 基本不会。实测 1,807,972 字节 binlog（20 万行），IO 线程在 2s 的观察窗口内就全部拉到了从库；而 SQL 线程把这同一批数据放完要 **3343ms** —— 它只负责搬，不做 SQL 解析和执行 |
| 网络传输 | 1 条 TCP | 跨机房时才会（RTT × 事务数、带宽打满） |
| **SQL 线程回放** | **默认 1 个** | **就是它。** 主库 20 并发写、从库 1 个线程放，队列只会越排越长 |

**大事务最致命**：一个事务在从库**不能拆**，只能一个线程从头放到尾，这期间后面所有事务全堵着。实测「50 万行一个事务」压出 8s 延迟就是这么来的 —— 而主库执行这条 `UPDATE` 自己只用了 4008ms，**从库的延迟比主库的执行时间还长**（多出来的那部分是「排队」）。

必须先把 `Seconds_Behind_Source`（下称 SBM）的口径说清楚，否则后面所有坑都解释不了：

```
SBM = 从库当前时间 − 当前正在回放的那个事件上记录的「主库提交时间」
```

它衡量的是**「SQL 线程手头这个事件有多旧」**，也就是 `排队时间 + 回放耗时`。推论（下面都实测到了）：

- 队列空 → 没有「正在回放的事件」 → **报 NULL，不是 0**；
- 一边用从库本地钟、一边用主库事件时间戳 → **主从时钟不一致会直接污染它**；
- 从库**故意落后 5 秒**（延迟从库的正当用法）时，事件在「等延迟」期间不算「正在回放」 → 它照样报 0。

### 二、实测（MySQL 8.4.11）

1. **大事务能压出多少延迟**（`bench/mysql/16-replication.sh`）：`sample_sbm 15`（每 0.3s 采一次，共 15 次）在 `UPDATE big SET v=v+1`（50 万行、单事务）期间采样，峰值 **8s**；追平耗时 **3059ms**。同一条 `UPDATE` 在主库自己执行耗时 **4008ms** —— 从库的延迟峰值约是它的 **2 倍**：从库要花几乎一样长的时间把这 50 万行放完，**而这段时间里后面所有事务都在排队**。脚本里专门写了一句提醒：**下一节测之前必须先 `wait_catchup`**，第一版没等，残留延迟和 5 秒叠加后读到 18s，看起来像「`SOURCE_DELAY` 没生效」。

2. **并行回放到底有没有用**（`bench/mysql/16-replication.sh` 第 5 节，压测端 `bench/mysql/16-load.php`）：主库用 20 条常驻连接 × 100 个独立小事务（共 2000 个事务，binlog 写入 702,443 字节），对比从库 `replica_parallel_workers`：

   | `replica_parallel_workers` | 从库回放到同一位点 | 折算回放速率 |
   | ---: | ---: | ---: |
   | 0（单线程） | 6379ms | ≈313 事务/s |
   | 4 | **1873ms** | ≈1068 事务/s |

   3.4x。当前配置是 `replica_parallel_type=LOGICAL_CLOCK`、`replica_preserve_commit_order=1`（保证从库提交顺序和主库一致）。
   **但并行回放不是万能的**：`LOGICAL_CLOCK` 靠的是主库 binlog 里「同一组提交」的标记，**主库上串行提交的事务，从库也只能串行放**。所以「加 worker」和「拆大事务」必须一起做 —— 上面第 1 条的 8s 峰值，加多少 worker 都救不了。

3. **延迟从库的真相：SBM=0 ≠ 同步了**。从库设 `SOURCE_DELAY=5`，主库插入一行几乎肯定没出现过的 id：

   | 时刻 | SBM | 从库有这行吗 | `SQL_Remaining_Delay` |
   | ---: | ---: | ---: | ---: |
   | t=1s | **0** | 0 | 5 |
   | t=2s | 2 | 0 | 4 |
   | t=3s | 3 | 0 | 2 |
   | t=4s | 5 | 0 | 1 |
   | t=5s | **0** | **1** | NULL |
   | t=6s | 0 | 1 | NULL |

   **t=1s 时 SBM 报 0，而那行数据根本还没到从库。** 第 5 秒数据一到，SBM 立刻掉回 0。
   延迟从库「正在等延迟」时，`Replica_SQL_Running_State` 是 `Waiting until SOURCE_DELAY seconds after source executed event` —— 这是识别「故意延迟的从库」的指纹。
   **结论：SBM 衡量的不是「数据有多旧」，而是「SQL 线程忙不忙」。** 判断延迟从库还剩多少没放，看 `SQL_Remaining_Delay`（在倒计时），不是 SBM。

4. **监控盲区实验 A：停掉 IO 线程**。主库写入 20 万行（`big2` 从空表开始）：

   ```
   主库 COUNT(*) = 200000
   从库 COUNT(*) = 0                    ← 数据真的没到
   Seconds_Behind_Source = [NULL]       ← 是 NULL（空），不是 0
   Replica_IO_Running    = No
   Replica_SQL_Running   = Yes          ← 还是 Yes！
   主库 Position = 689337505   从库 Read_Source_Log_Pos = 687529533
   还没被拉到从库的字节数 = 1807972
   ```

   (SBM 的字段名在 8.4 已经是 `Seconds_Behind_Source`，老版本叫 `Seconds_Behind_Master`。)
   **绝大多数监控写的是「if sbm > 阈值 then 报警」，NULL 的比较永远为假 → 复制彻底断了反而不报警。** 更迷惑的是 `Replica_SQL_Running` 仍然显示 `Yes`，只有 IO 那个变成了 `No`。恢复 IO 线程后追平这 20 万行用了 **1670ms**。

5. **监控盲区实验 B（比 A 更狠）：只停 SQL 线程、IO 线程照常拉**。

   ```
   【起点】  IO=Yes  SQL=No   Read=696057724  Exec=696057724  Relay_Log_Space=3616637  SBM=[NULL]
   【写完 20 万行】IO=Yes  SQL=No   Read=697865696  Exec=696057724  Relay_Log_Space=5424609  SBM=[NULL]
                                  ↑ 前进了 1807972 字节    ↑ 一动不动（差 1807972 字节没回放）
   ```

   **IO 线程在正常跑、relay log 里实实在在压着 1.8MB 没回放的数据，SBM 依然是 NULL。**
   把两个实验合起来看：**SBM 报 NULL 只说明「SQL 线程现在没在回放东西」，它既不能证明复制断了，也不能证明没延迟。** 恢复 SQL 线程后回放这 20 万行用了 **3343ms**。
   还有一个危险信号：`Relay_Log_Space` 从 3.6MB 涨到 5.4MB —— SQL 线程停着时 relay log 会一直涨，**从库磁盘先被写满**，这比延迟本身更致命。
   补充：完全 `STOP REPLICA`（两个线程都停）时，`IO=No SQL=No SBM=NULL`。

6. **读写分离的真正代价：写后读从库**（`bench/mysql/20-read-write-split.php`）。用两条常驻 PDO 连接（主、从各一条）跑 500 轮「写主库 → 立刻按主键读从库」：

   ```
   读到旧数据（从库查不到这一行）的次数 = 500 / 500 = 100.0%
   「轮询等到可见」耗时：min 3.32ms  p50 4.54ms  p90 5.62ms  max 67.33ms  平均 4.82ms
   同一条主键点查 ×2000：主库 464ms（0.232ms/次）  从库 451ms（0.225ms/次）
   ```

   **异步复制下不是「偶尔读到旧数据」，是 100%。** 而且因为从库还有并行的读请求/回放抖动，尾延迟到 67ms 很正常。这里必须用常驻连接测：用 `for` + `docker exec` 每次都要起进程、建连接（~30ms），陈旧窗口会被完全淹没，测出来的「延迟」其实是进程启动开销。

7. **同一条主键点查主从几乎一样快**（0.232 vs 0.225ms）。读写分离买到的是「把并发读从主库挪走」（能扛更多 QPS、避免从库查询拖垮主库），**不是**让单条查询变快。

8. 延迟的另一种量法（字节维度）：这台 MySQL 上 **20 万行 = 1,807,972 字节 binlog**（两个 int 列的表，约 9 字节/行），2000 个小事务 = 702,443 字节。所以 `Read_Source_Log_Pos − Exec_Source_Log_Pos` 是「还没回放多少字节」，`主库 Position − 从库 Exec_Pos` 是「还没到手的数据量」——都比 SBM 更接近「差了多少数据」。

**未实测（本环境量不出来的）**：

- 「从库上跑大查询会拖慢回放」这条常识**方向是对的**（CPU/IO/锁竞争、大查询的长期一致性读视图会挡住 purge），但本环境测不出可信差值：从库空闲时回放 2299ms，从库同时跑 25 次 `COUNT(*)`（50 万行全表）时 2716ms，**+18%**，而同一负载重复跑本身就在 1873ms ~ 2299ms 之间波动（±20%）。**主从在同一台宿主机上，CPU/IO 是共享的，「从库被读请求拖慢」和「宿主整体被压」分不开**，所以这个数字不作为结论。
- 跨机房的网络延迟与带宽瓶颈、半同步复制（`AFTER_SYNC`）的确认等待耗时、GTID 多线程回放的 write-set 冲突回滚率、组复制（MGR）的流控 —— 主从同宿主，量不出来。
- `WAIT_FOR_EXECUTED_GTID_SET()`：本实例 `gtid_mode=OFF`，没有 GTID，这个「等位点」方案没实测。

### 三、五个反直觉的点

1. **`Seconds_Behind_Source` 这三个值（0 / NULL / 大数）没有一个能回答「从库数据是不是最新的」。** 延迟从库在正常工作时它报 0（实测差 5 秒）；SQL 线程停掉、1.8MB 积压时它报 NULL；它只在「SQL 线程回放的这一刻」取值，所以从库一追平就立刻归零。**唯一可靠的判据是 `Replica_IO_Running` + `Replica_SQL_Running` 两个 Yes/No，加上 `Read_Pos − Exec_Pos` 在不在扩大。**

2. **SBM 是个「瞬时值」，不是「一段时间内的延迟」。** 8s 的延迟在追平后立刻变回 0，事后面板看曲线经常什么都看不到（尤其采样间隔大于追平耗时的时候）。要么提高采样频率，要么用字节差 `Read_Pos − Exec_Pos` 当告警项。

3. **`replica_parallel_workers` 不是银弹。** `LOGICAL_CLOCK` 依赖主库写入的「同一组提交」信息：主库自己就是串行提交的事务，从库再多个线程也只能一个一个放。实测 2000 个**并发**小事务能加速 3.4x，而单个 50 万行的大事务一点都加不了速（还是 8s）。**治延迟要「拆大事务 + 加并行度」一起做。**

4. **写后读从库的失败率是 100%，不是「偶尔」。** 实测 500/500 全中，p50 也要 4.5ms 后才可见。所以「写主读从」不是一个可以靠「一般不会那么巧」糊弄过去的问题 —— 它必须有一套读己之写的方案（见下），否则用户改完昵称刷新页面还是旧的，这就是线上事故。

5. **SQL 线程停掉时 relay log 会一直涨**（实测 3.6MB → 5.4MB，还在涨）。延迟大只是「读到旧数据」，relay log 把从库磁盘写满会让从库直接挂 —— 这才是复制故障的第一优先级。

### 四、实战结论

| 现象 | 该看什么 | 怎么处理 |
| --- | --- | --- |
| SBM 忽高忽低、偶发几秒 | `Read_Pos − Exec_Pos` 是否同步波动 | 主库上找**大事务**（`binlog` 里单事务字节数、`events_statements_summary_by_digest` 的 `SUM_ROWS_AFFECTED`）→ 改成按主键区间分批的小事务 |
| 延迟持续增长、追不上 | `Exec_Pos` 是否在前进、`Relay_Log_Space` 是否持续涨 | 回放吞吐不够：加 `replica_parallel_workers`（配合 `LOGICAL_CLOCK`）、确认从库没在跑重查询/大事务、检查从库磁盘 IO |
| SBM = NULL | **先看两个 Running** | `IO=No` → 网络/主库 dump 线程/权限；`SQL=No` → 看 `performance_schema.replication_applier_status_by_worker` 的 `LAST_ERROR_NUMBER`；**告警必须同时判 IO 和 SQL 这两个 Yes/No**，只判 SBM 会把「复制断了」漏掉 |
| SBM 长期固定在一个数（如 5s） | `SQL_Delay` / `SQL_Remaining_Delay` | 这是**延迟从库**的正常行为，不是故障。要判断还剩多少没放看 `SQL_Remaining_Delay` |
| 主库 `Position` 涨得比从库 `Exec_Pos` 快很多 | 字节差 `主库 Position − 从库 Exec_Pos` | 用「差了多少字节」而不是 SBM 做容量/告警 |
| 写完立刻读从库读到旧值 | — | 见下面的「读己之写」方案表 |

**延迟的治理手段（按性价比排序）**：

| 手段 | 说明 | 本环境实测 |
| --- | --- | --- |
| 拆大事务 | 一个事务在从库不能拆，只能串行放。批量 `UPDATE`/`DELETE`/DDL 按主键区间分批 | 单事务 50 万行 → 8s 峰值；拆小后同理的数据量没有大峰值 |
| 并行回放 | `replica_parallel_workers=4/8/16` + `LOGICAL_CLOCK`；8.0 起 `replica_preserve_commit_order=1` 保证提交顺序 | **3.4x**（6379ms → 1873ms） |
| 从库不跑重查询 | 大查询占 CPU/IO，还会用长时间一致性读视图挡住 purge | 本环境分离不出可信差值（见「未实测」） |
| 延迟从库 | `SOURCE_DELAY=N`：故意落后 N 秒，用来做「误删了还能捞回来」的兜底 | SBM=0 / `SQL_Remaining_Delay` 倒计时（上面第 3 条） |
| 半同步 / 组复制 | 让主库等从库 ACK，牺牲写延迟换「提交即不丢」 | **未实测**（同宿主，无跨机房 RTT） |

**读写分离下保证读到最新数据（读己之写）的方案**：

| 方案 | 做法 | 代价 |
| --- | --- | --- |
| 写后一段时间强制走主库 | 同一会话/同一用户写后 N 秒内的读都发往主库 | 简单，但 N 只能拍脑袋；实测可见窗口 p50 4.5ms、max 67ms，N 要留足余量 |
| 等位点 | 记下写操作的主库 binlog 位点，读从库前先确认 `Exec_Source_Log_Pos ≥ 位点`，不够就阻塞等待 | 精确、只等必要的量；实测等待 p50 4.54ms。要把位点随请求带过去（一般是放进 cookie/上下文） |
| GTID 等待 | `WAIT_FOR_EXECUTED_GTID_SET(gtid, timeout)` | 最干净，但要求开 GTID（本实例 `gtid_mode=OFF`，**未实测**） |
| 外部心跳表 | 主库定时写时间戳，从库读出来和本地 `now()` 比，超过阈值就把读切回主库 | 绕开 SBM 的 NULL/时钟问题，生产最常用；需要额外的写压力 |
| 关键路径直接读主库 | 支付、下单回显这类「绝对不允许旧数据」的路径 | 最可靠，但主库压力回来了 —— 所以只给关键路径用 |

### 五、可能的追问

| 追问 | 回答 |
| --- | --- |
| `Seconds_Behind_Source` 到底怎么算的？为什么会有 NULL？ | 它是「从库当前时间 − 当前正在回放的事件里记录的主库提交时间」。没有「正在回放的事件」时（队列空、线程停了、在等 `SOURCE_DELAY`）就只能返回 NULL。也正因为它拿从库的钟去减主库的时间戳，**主从时钟不一致会直接让它算错** |
| 半同步复制能消除延迟吗？ | 不能。半同步只保证「主库提交时至少一个从库收到了 binlog」，解决的是**丢数据**，不解决**回放**。它甚至会让主库写延迟变高（要等 ACK）。而且 `AFTER_SYNC` 下主库等的是「收到」不是「回放完」，从库仍然可能落后 |
| 为什么从库要串行回放？不能全并行吗？ | 因为事务之间可能有依赖（同一行的并发更新、外键、唯一键冲突），并行回放必须保证「有冲突的事务按原顺序」。`LOGICAL_CLOCK` 用主库的提交组来判定「这批事务互不冲突」。所以主库串行提交的事务，从库没法并行 |
| `replica_preserve_commit_order=1` 有什么用？ | 保证从库上的提交顺序和主库一致。关掉它并行回放更快，但**在从库上做备份/读时可能看到主库上从未存在过的中间状态**（比如先看到 B 表的新值再看到 A 表的），一般对 `LOGICAL_CLOCK` + 需要一致性快照的场景必须开着 |
| 从库延迟大，我能直接从从库上做备份吗？ | 备份本身没问题，但延迟大意味着**备份出来的数据点是旧的**。备份工具会记录位点，恢复时用得上；关键是「这个位点对应的时间」要一起记下来 |
| 延迟从库有什么用？ | 误删表/误更新这类操作，主库上立刻生效，普通从库也立刻跟着回放（错误同样被复制）。延迟 N 秒的从库给了你一个「N 秒前的世界」，可以从它上面把误删前的数据捞出来。代价是它永远落后 N 秒，不能用于读写分离的读流量 |
| 主从延迟会导致数据不一致吗？ | 异步复制下，**主库宕机 + 从库未回放完 = 丢事务**。这也是为什么 `sync_binlog=1`、`innodb_flush_log_at_trx_commit=1`（本实例都是 1）只能保证「主库自己不丢」，跨机不丢要靠半同步/组复制 |
| 读写分离的读流量全打到从库，从库怎么扛？ | 加从库（一主多从，dump 线程是主库侧的额外成本）、从库上只放读、按业务分从库（报表类查询单独一个从库，别和在线读混在一起）。**别让大查询和在线读共用一个从库** |
| 主从切换（failover）之后延迟会消失吗？ | 不会，可能更糟。切换后所有写都打在新主库上，而其他从库要重新指向新主库、可能还需要从旧主库捞完剩余的 relay log。切换期间是延迟最大的时候，也是「读己之写」方案最容易被忽略的时候 |
| 怎么监控才能不漏掉「复制断了」？ | 三件套：①`Replica_IO_Running` 和 `Replica_SQL_Running` 都必须 `Yes`；②`Read_Pos − Exec_Pos` 在不在扩大；③心跳表（主库写时间戳、从库读出来和本地时间比）。**只盯 `Seconds_Behind_Source` 一定会漏**，因为复制彻底断了它报的是 NULL，而 `NULL > 阈值` 永远为假 |

---

## Q24. 什么时候该分库分表？分片键怎么选？

### 结论

**分片是最后手段，不是第一手段。** 在这之前有四级台阶，每一级的代价都远小于分片：

```
① SQL / 索引优化       ← 90% 的「表太大」问题其实卡在这里（见 Q21）
② 归档 / 冷热分离       ← 把「历史数据」挪走，热表自然就小了
③ 读写分离（从库）      ← 解决「读」的容量，不解决「写」和「单表大小」
④ 分区表（PARTITION）  ← 单表逻辑上还是一个表，但物理分文件，DDL/归档友好
─────────── 以上都不够，才考虑 ───────────
⑤ 垂直拆分（按业务拆库）  ← 先拆「不相关的业务」，成本最低的一种「分」
⑥ 水平分片（sharding）  ← 代价最大：跨片查询、跨片事务、全局 ID、扩容 rehash
```

一句话判据：**先问「是读不够、写不够、还是单表太大」，三个问题的解法完全不同。** 只有「单表太大导致写入瓶颈 / DDL 停不下来 / 索引深到影响查询」才是分片的理由。

分片键的选择只有三条硬标准：**① 高基数**（能散开）、**② 分布均匀**（不倾斜）、**③ 能覆盖绝大多数查询的 `WHERE` 条件**（不跨片）。第三条最容易被忽略，也最贵 —— 本次实测：**分片键命中只打 1 个分片（0.212 ms），非分片键查询要打满 4 个分片（0.693 ms，3.3 倍）**。

而且**「行数均匀」不等于「写入均匀」**：实测按月份分片时，4 个片行数几乎持平（最多/最少 = 1.03），但**最近 30 天的新写入 100% 落在其中 2 个片上，另外 2 个片是 0**。

### 一、分片键选错的两种典型形态

```
【形态 1】查询打满所有分片

  按 user_id 分片，但业务按 order_no 查订单：
       app ──┬──> shard0  WHERE order_no=?   ┐
             ├──> shard1  WHERE order_no=?   │  4 条 SQL，串行
             ├──> shard2  WHERE order_no=?   │  真分片还要 + 4 次网络 RTT
             └──> shard3  WHERE order_no=?   ┘  再在应用层归并
       ← 分片前这是 1 条 SQL。延迟不降反升

【形态 2】写入全压在一个分片（「行数均匀」掩盖了「写入倾斜」）

  按 MONTH(created_at) % 4 分片：
      shard0  151,248 行   ← 最近 30 天新写入 8,220
      shard1  151,237 行   ← 最近 30 天新写入 16,439
      shard2  146,285 行   ← 最近 30 天新写入 0
      shard3  151,230 行   ← 最近 30 天新写入 0
      总行数很均衡（1.03），但**此刻所有写入都打在 shard0 和 shard1 上**
      → 分片的意义是「把写打散」，结果写一点没散
```

**第二种更危险**，因为它看起来是健康的：容量评估看行数、行数是均匀的，等到发现写入瓶颈时，业务已经跑在上面了。

### 二、实测（MySQL 8.4.11）

> **重要声明**：本环境只有**一台** MySQL 实例，下面的「分片」是在同一实例里建 4 张 `orders_0..3` 表 + 应用层路由来**模拟**的。因此：
> - 测得出的是 **SQL 条数、扫描量、行数分布、DDL 耗时** —— 这些是真的；
> - **测不出**跨机器的网络 RTT、部分失败重试、跨片分布式事务、扩容 rehash 的搬迁量 —— 这些在下面明确标「**未实测**」，不编数字。

**1）不分片时，单表变大到底哪里变贵**（`one_big` 597,780 行，聚簇 39 MB + 索引 36 MB = 75 MB）：

| 操作 | 耗时 |
| --- | ---: |
| 主键点查 | 0.278 ms |
| `user_id` 二级索引点查 | 0.400 ms |
| `COUNT(*)` 全表 | 77.000 ms |
| 「最近 7 天」范围扫描 | 112.918 ms |

**点查 0.278 ms 完全没问题**（B+Tree 3 层，见 Q13）。**贵的是聚合和范围扫描**——它们和总行数成正比。所以「单表大」的用户体感首先出现在**列表页 / 报表 / `COUNT(*)`**，而不是详情页。

**2）分片键命中：只打 1 个分片**：

```
user_id=777 落到 orders_1：1 条 SQL，0.212 ms
```

**3）非分片键查询：必须打满 N 个分片再归并**：

| 查询 | SQL 条数 | 耗时 | 相对单片 |
| --- | ---: | ---: | ---: |
| 按 `order_no`（非分片键）精确查 | 4 | 0.693 ms | **3.3x** |
| 跨片 `COUNT(*)` | 4（相加） | 75.922 ms | — |
| 跨片「金额 TOP10」 | 4（各取 TOP10）+ 应用层归并 40 行 | **196.197 ms** | — |

**`order_no` 那条最值得注意**：结果只有 1 行，但**延迟是分片键命中的 3.3 倍**，而且这还只是 4 个分片。**分片数越多，这类查询越慢**——因为它随分片数线性增长。所以「非分片键查询多不多」是分片键选型的第一考量。

跨片 TOP10 的 196 ms 更说明问题：**归并逻辑被迫上移到应用层**，数据库帮不了你。分片之后，「排序 + 分页 + 聚合」这三件事全都变复杂了。

**4）分片键的分布：行数均匀 ≠ 写入均匀**：

```
按 user_id 分片        150000 / 150000 / 150000 / 150000   → 最多/最少 = 1.00
按月份分片             151248 / 151237 / 146285 / 151230   → 最多/最少 = 1.03
```

行数看都是健康的。但把「最近 30 天新写入」单独拎出来：

```
按 MONTH(created_at)%4 分片：
  片 0   总 151,248   最近 30 天新写入  8,220
  片 1   总 151,237   最近 30 天新写入 16,439
  片 2   总 146,285   最近 30 天新写入      0     ← 完全没有写入
  片 3   总 151,230   最近 30 天新写入      0     ← 完全没有写入
```

**写入集中度 100% 落在 2 个片上。** 时间维度分片的本质缺陷就是这个：历史数据分布得再均匀，**新数据永远只进「当前时间」那个桶**。用 `HASH(created_at)` 能打散写入，但那样「按时间查最近 7 天」就要打满所有分片 —— 两个目标互斥。

**5）全局唯一 ID：自增不能用了，随机主键的代价**（每批 1000 行多值 INSERT，共 50,000 行，取 3 轮最小值）：

| 主键策略 | 插入耗时 | 相对 | 聚簇索引占用 |
| --- | ---: | ---: | ---: |
| `AUTO_INCREMENT`（严格递增） | 570 ms | 基准 | 2.52 MB |
| 雪花 ID（趋势递增，局部乱序） | 636 ms | 1.12x | 2.52 MB |
| 随机 ID（完全无序） | 610 ms | 1.07x | **3.52 MB（1.40x）** |

**插入耗时差别不大（1.07~1.12 倍），但空间差了 40%。** 原因：随机主键的插入是**随机位置写**，会不断触发**页分裂**，分裂后两页各约 70% 满，于是同样的行数要多占 40% 的页。雪花 ID 因为「趋势递增」，插入集中在右端，几乎不分裂，空间和自增一样。

**这个 40% 是复利的**：聚簇索引多占 40% → buffer pool 命中率下降 → 所有二级索引都存主键，二级索引也变大。所以**分片后主键不要用 UUID（v4）**，要用雪花 / ULID 这类「趋势递增」的 ID。

**6）DDL：分片最硬的理由**：

| 表 | 行数 | 加一个索引耗时 |
| --- | ---: | ---: |
| `orders_0`（一个分片） | 15 万 | 455 ms |
| `one_big`（不分片） | 60 万 | **1,685 ms（3.7x）** |

而且加完后 `one_big` 的索引从 36 MB 涨到 49 MB —— **这部分内存和 IO 是净增的**。

DDL 的耗时大致随表大小线性增长，而 `ALTER` 在 `ALGORITHM=INPLACE, LOCK=NONE` 下虽然不阻塞读写，但**要消耗大量 IO 和磁盘空间**，大表上跑几小时很常见，期间还可能因为磁盘/undo 压力影响正常业务。**分片之后每次只需要改 1/N 的表**，这是分片最实际、最不容易被反驳的理由。

**未实测**：真·跨机器分片的网络 RTT、部分失败重试、跨片分布式事务、扩容时 rehash 的搬迁量 —— 本环境只有一台 MySQL 实例，这些量不出来，**不编数字**。

### 三、四个反直觉的点

**1. 「单表超过 2000 万行就该分」是个没有依据的经验值。**

本次实测：60 万行的单表，**主键点查只要 0.278 ms**，因为 B+Tree 只有 3 层（Q13 实测：500 万行以内都是 3 层，3 层能装约 1.88 亿行）。

所以「行数」本身不是问题，**真正的问题是**：
- `COUNT(*)` / 范围扫描 / 报表（随行数线性增长，实测 77 ms / 113 ms）；
- **DDL 时间**（实测 3.7 倍差距）；
- **单机磁盘容量**；
- **写入吞吐**（单机 binlog / redo 的极限）。

**「多少行」不该是判据，「哪个操作扛不住了」才是。**

**2. 分片之后，跨片查询的延迟是「乘以分片数」，不是「加一点」。**

实测 4 个分片时非分片键查询是 3.3 倍。如果分 64 片，理论上就是 ~64 倍（串行）或至少 64 次网络往返（并行也要受最慢的一片拖累）。

**所以分片键的选择，本质上是「选择一个能让绝大多数查询都只打一个分片的维度」。** 如果业务上根本不存在这样的维度（每条查询的过滤条件都不一样），**那就不该分片** —— 应该考虑换存储（ES / 数仓 / 列存）来解决查询问题。

**3. 分片后「全局唯一 ID」不是小事，它会反向影响你的主键设计。**

自增主键在分片后不可用（各片各自自增会撞）。三种替代方案各有代价：

| 方案 | 代价 |
| --- | --- |
| 雪花 / ULID | 需要时钟同步；时钟回拨要处理。**好处：趋势递增，索引不分裂** |
| UUID v4 | 无需协调、绝对唯一。**代价：实测聚簇索引多占 40%，且所有二级索引都被撑大** |
| 集中式发号器（Redis/DB 段号） | 简单可靠，但**发号器本身成了单点和瓶颈** |

实测数据支持选第一种：雪花和自增的空间占用**完全一样（2.52 MB）**，而随机 ID 是 3.52 MB。

**4. 「分片能提升性能」是错的；分片换来的是可扩展性，代价是性能。**

同一个查询，分片后**只会更慢或持平**：
- 分片键命中 → 1 条 SQL，和分片前一样快（甚至更快，因为单表小了）；
- 非分片键 → **必然更慢**（×N）。

分片真正买到的是：**写入可以水平扩展、单表变小所以 DDL 快、磁盘可以分散到多台机器**。**如果你的瓶颈是「查询慢」而不是「写入吞吐 / 单表容量」，分片帮不上忙。**

### 四、实战结论

| 场景 | 做法 |
| --- | --- |
| 「表太大了怎么办」 | 先按 ①索引/SQL ②归档冷热分离 ③读写分离 ④分区表 ⑤垂直拆 ⑥水平分片 的顺序排查，**别直接跳到⑥** |
| 什么时候才真该分片 | 单机写入吞吐到顶 / 磁盘装不下 / DDL 时间不可接受。**「查询慢」不是分片理由** |
| 分片键三条标准 | 高基数 + 分布均匀 + **能覆盖绝大多数 WHERE 条件**。第三条最重要 |
| 分片键首选 | 用户 ID / 租户 ID 这类「业务上天然带、且几乎每个查询都会带」的维度 |
| 千万别用 | 时间戳（写入全压当前桶，实测：行数均匀但新写入 100% 集中）、低基数列（如状态、性别） |
| 分片后的主键 | 雪花 / ULID（趋势递增）。**不要用 UUID v4**（实测索引多占 40%） |
| 非分片键查询怎么办 | 建「映射表 / 基因法」：把分片键冗余进 ID（如 `order_no` 末位嵌入 `user_id` 的分片位），让它能直接路由 |
| 跨片聚合 / 排序 / 分页 | 尽量下推到各片算局部结果，再在应用层归并；**避免跨片 `COUNT(*)` 和深分页** |
| 扩容 | 尽量**成倍扩容**（4→8）配合一致性哈希 / 预分片（一开始就分 1024 个逻辑片，映射到少量物理库），避免全量 rehash |
| 跨片事务 | 尽量设计成「单分片内闭环」；实在不行用本地消息表 / Saga，**不要上 XA** |

### 五、可能的追问

| 追问 | 要点 |
| --- | --- |
| 分区表（PARTITION）和分片有什么区别？ | 分区是**单实例内**物理分文件，SQL 层还是一个表（对应用透明），解决 DDL/归档/扫描裁剪；分片是**跨实例**，解决写入和容量。**分区不能替代分片，但能推迟分片的到来** |
| 一致性哈希解决什么？ | 扩缩容时只搬迁 1/N 的数据，而不是全量 rehash。虚拟节点用来解决数据倾斜 |
| 预分片（逻辑分片）是什么？ | 一开始就分成很多逻辑片（如 1024），逻辑片→物理库的映射放在配置/元数据里。扩容时只需改映射、搬少量逻辑片，不用改分片算法 |
| 分片后怎么做分页？ | 各片各自 `LIMIT offset+N`，应用层归并再截断。**深分页会更糟**（Q22 的问题 × 分片数） |
| 分片后 `JOIN` 怎么办？ | 同分片键的 JOIN 可以下推到片内；跨片 JOIN 基本不可行，要靠**冗余字段**或**应用层拼装** |
| 基因法（分片基因）是什么？ | 把分片键的哈希位拼进业务 ID 的低位（如 `order_no` 末 10 位含 `user_id` 的哈希），这样拿 `order_no` 也能算出落在哪个分片，解决「非分片键查询」 |
| 中间件方案有哪些？ | ShardingSphere / Vitess / MyCat。**代价是运维复杂度 + 部分 SQL 不支持**，能不上就不上 |
| 什么时候该考虑换存储而不是分片？ | 查询模式多样、聚合多、全文检索、日志类 —— 这类需求分片解决不了，应该上 ES / ClickHouse / 数仓 |
| 分片数怎么定？ | 建议**成倍**（4/8/16）方便扩容；同时考虑「每片的数据量」不要太大（留出增长空间）和「分片数不要超过连接池/运维能承受的上限」 |
| MySQL 自己有什么分片能力？ | 没有原生分片。`NDB Cluster` 有，但生态和 InnoDB 差别很大。MySQL 的定位就是单机关系库，分片是应用层的事 |
