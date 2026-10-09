"""Сборка сайта: documents/ (PDF, DOCX, TXT) -> public/ (страница, сами файлы и поисковый индекс).
Сервера у сайта нет: поиск считается в браузере по индексу, собранному здесь.
Запуск:  python build.py   ->  папка public/ (её и публикуют)."""
import hashlib
import json
import math
import re
import shutil
import time
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path

BASE = Path(__file__).parent
SRC, WEB, OUT, CACHE = BASE / "documents", BASE / "web", BASE / "public", BASE / ".cache"
EXTS = {".pdf", ".docx", ".txt", ".md"}
CHUNK_CHARS = 1200       # размер фрагмента текста
PER_FILE = 40            # фрагментов в одном файле с текстами
SHARD_BYTES = 60_000     # примерный размер одного файла индекса
K1, B = 1.5, 0.75        # BM25

STOP = set("""и в во не что он на я с со как а то все она так его но да ты к у же вы за бы по
только ее мне было вот от меня еще нет о из ему теперь когда даже ну вдруг ли если уже или ни быть
был него до вас нибудь опять уж вам сказал ведь там потом себя ничего ей может они тут где есть надо
ней для мы тебя их чем была сам чтоб без будто чего раз тоже себе под будет ж тогда кто этот того
потому этого какой совсем ним здесь этом один почти мой тем чтобы нее сейчас были куда зачем всех
никогда можно при наконец два об другой хоть после над больше тот через эти нас про всего них какая
много разве три эту моя впрочем хорошо свою этой перед иногда лучше чуть том нельзя такой им более
всегда конечно всю между какие такое такие какой какую каком который которая которые которого
""".split())

# ---- слова и их основы. Стеммер и разбор слов повторены в web/index.html - они должны совпадать буква в букву.
_V = "аеиоуыэюя"
_RV = re.compile(rf"^(.*?[{_V}])(.*)$")
_DERIV = re.compile(rf"[^{_V}][{_V}].*ость?$")
# пары (окончания без условий, окончания только после а/я)
_GERUND = (re.compile(r"(ив|ивши|ившись|ыв|ывши|ывшись)$"), re.compile(r"[ая](в|вши|вшись)$"))
_ADJ = (re.compile(r"(ее|ие|ые|ое|ими|ыми|ей|ий|ый|ой|ем|им|ым|ом|его|ого|ему|ому|их|ых|ую|юю|ая|яя|ою|ею)$"), None)
_PART = (re.compile(r"(ивш|ывш|ующ)$"), re.compile(r"[ая](ем|нн|вш|ющ|щ)$"))
_VERB = (re.compile(r"(ила|ыла|ена|ейте|уйте|ите|или|ыли|ей|уй|ил|ыл|им|ым|ен|ило|ыло|ено|ят|ует|уют|ит|ыт|ены|ить|ыть|ишь|ую|ю)$"),
         re.compile(r"[ая](ла|на|ете|йте|ли|й|л|ем|н|ло|но|ет|ют|ны|ть|ешь|нно)$"))
_NOUN = (re.compile(r"(а|ев|ов|ие|ье|е|иями|ями|ами|еи|ии|и|ией|ей|ой|ий|й|иям|ям|ием|ем|ам|ом|о|у|ах|иях|ях|ы|ь|ию|ью|ю|ия|ья|я)$"), None)


def _cut(rv: str, free, bound) -> str | None:
    """Снимает самое длинное подходящее окончание; None - если ни одно не подошло."""
    a, b = free.search(rv), bound.search(rv) if bound else None
    n = max(len(a.group()) if a else 0, len(b.group()) - 1 if b else 0)
    return rv[:-n] if n else None


def stem(word: str) -> str:
    m = _RV.match(word)
    if not m:
        return word
    pre, rv = m.groups()
    t = _cut(rv, *_GERUND)
    if t is None:
        rv = re.sub(r"(ся|сь)$", "", rv)
        t = _cut(rv, *_ADJ)
        if t is not None:
            p = _cut(t, *_PART)
            t = t if p is None else p
        else:
            t = _cut(rv, *_VERB)
            if t is None:
                t = _cut(rv, *_NOUN)
            if t is None:
                t = rv
    rv = t
    if rv.endswith("и"):
        rv = rv[:-1]
    if _DERIV.search(rv):
        rv = re.sub(r"ость?$", "", rv)
    if rv.endswith("ь"):
        rv = rv[:-1]
    else:
        rv = re.sub(r"ейше?$", "", rv)
        if rv.endswith("нн"):
            rv = rv[:-1]
    return pre + rv


def tokens(text: str) -> list[str]:
    words = re.findall(r"[a-zа-я0-9]+", text.lower().replace("ё", "е"))
    return [stem(w) for w in words if w not in STOP and (len(w) > 1 or w.isdigit())]


def fnv(s: str) -> int:
    """Номер файла индекса по слову (тот же расчёт - в web/index.html)."""
    h = 2166136261
    for c in s:
        h = ((h ^ ord(c)) * 16777619) & 0xFFFFFFFF
    return h


# ---- чтение документов
def read_parts(path: Path) -> list[tuple[str, str]]:
    ext = path.suffix.lower()
    if ext == ".pdf":
        from pypdf import PdfReader
        return [(f"стр. {i}", p.extract_text() or "") for i, p in enumerate(PdfReader(path).pages, 1)]
    if ext == ".docx":
        return _docx_parts(path)
    raw = path.read_bytes()
    try:
        text = raw.decode("utf-8-sig")
    except UnicodeDecodeError:
        text = raw.decode("cp1251", errors="replace")
    lines = text.splitlines()
    return [(f"часть {i // 60 + 1}", "\n".join(lines[i:i + 60])) for i in range(0, len(lines), 60)]


def _docx_blocks(d):
    """Абзацы и таблицы в порядке следования в документе."""
    from docx.table import Table
    from docx.text.paragraph import Paragraph
    for el in d.element.body.iterchildren():
        if el.tag.endswith("}p"):
            p = Paragraph(el, d)
            if p.text.strip():
                yield "p", p.text.strip()
        elif el.tag.endswith("}tbl"):
            rows = []
            for r in Table(el, d).rows:
                cells = []
                for c in r.cells:
                    x = " ".join(c.text.split())
                    if not cells or cells[-1] != x:
                        cells.append(x)
                rows.append(" | ".join(cells))
            yield "t", "\n".join(rows)


def _docx_parts(path: Path, per_part: int = 10) -> list[tuple[str, str]]:
    """Режем на части по ~10 абзацев; метка = «Приложение, раздел, с п. N»."""
    import docx
    d = docx.Document(path)
    appendix, section, parts, buf, first_pt = "", "", [], [], ""

    def flush():
        nonlocal buf, first_pt
        if buf:
            label = ", ".join(x for x in (appendix, section) if x) or "начало документа"
            parts.append((label + (f", с п. {first_pt}" if first_pt else ""), "\n".join(buf)))
        buf, first_pt = [], ""

    for kind, text in _docx_blocks(d):
        if kind == "p":
            m = re.match(r"^Приложение N\s*(\d+)", text)
            if m:
                flush(); appendix, section = f"Приложение N {m.group(1)}", ""
            elif re.match(r"^[IVX]+\.\s", text) and len(text) < 160:
                flush(); section = "раздел " + text.rstrip(",")
            pm = re.match(r"^(\d+(?:\.\d+)*)\.?\s", text)
            if pm and not first_pt:
                first_pt = pm.group(1)
        buf.append(text)
        if len(buf) >= per_part:
            flush()
    flush()
    return parts


def parts_of(path: Path) -> list:
    """Текст документа; разобранное хранится в .cache, чтобы не читать те же файлы при каждой сборке."""
    hit = CACHE / (hashlib.sha1(path.read_bytes()).hexdigest() + ".json")
    if hit.exists():
        return json.loads(hit.read_text("utf-8"))
    parts = read_parts(path)
    CACHE.mkdir(exist_ok=True)
    hit.write_text(json.dumps(parts, ensure_ascii=False), "utf-8")
    return parts


def clean(text: str) -> str:
    """Чинит то, что ломает и поиск, и чтение: невидимые символы, перенос слова на новую строку, Р А З Р Я Д К У."""
    t = re.sub("[­​-‏⁠﻿]", "", text).replace(" ", " ").replace(" ", " ")
    t = re.sub(r"([а-яё])-[ \t]*\n\s*([а-яё])", r"\1\2", t)
    return re.sub(r"(?<!\S)(?:[А-ЯЁA-Z] ){3,}[А-ЯЁA-Z](?!\S)", lambda m: m.group().replace(" ", ""), t)


def split_text(text: str) -> list[str]:
    out, cur = [], ""
    for p in (p.strip() for p in re.split(r"\n+", re.sub(r"[ \t]+", " ", text))):
        if not p:
            continue
        if cur and len(cur) + len(p) + 1 > CHUNK_CHARS:
            out.append(cur)
            cur = p
        else:
            cur = (cur + " " + p).strip()
    return out + [cur] if cur else out


# ---- сборка
def dump(path: Path, obj):
    path.write_text(json.dumps(obj, ensure_ascii=False, separators=(",", ":")), "utf-8")


def build():
    nfc = lambda s: unicodedata.normalize("NFC", s)
    found = sorted(p for p in SRC.iterdir() if p.is_file() and not p.name.startswith(".")) if SRC.exists() else []
    for p in found:
        if p.suffix.lower() not in EXTS:
            print(f"пропущен: {p.name} (нужен PDF, DOCX или TXT)")
    files = [p for p in found if p.suffix.lower() in EXTS]

    docs, chunks = [], []            # chunks: (номер документа, метка, текст)
    for path in files:
        try:
            parts = parts_of(path)
        except Exception as e:       # битый файл не должен ронять весь сайт
            print(f"НЕ ПРОЧИТАН: {path.name}: {e}")
            parts = []
        start = len(chunks)
        for label, text in parts:
            chunks += [(len(docs), label, piece) for piece in split_text(clean(text))]
        docs.append({"name": nfc(path.stem), "file": nfc(path.name), "parts": len(parts), "chunks": len(chunks) - start})
        note = "" if len(chunks) > start else "  <- нет текста (скан?), поиск по файлу работать не будет"
        print(f"{path.name}: {len(parts)} стр./частей, {len(chunks) - start} фрагментов{note}")

    counts = [Counter(tokens(text)) for _, _, text in chunks]
    lens = [sum(c.values()) for c in counts]
    n, avg = len(chunks), (sum(lens) / len(lens) if lens else 1) or 1
    df = Counter(t for c in counts for t in c)
    post = defaultdict(list)         # основа слова -> [фрагмент, вес, фрагмент, вес, ...]; вес = BM25 x 100
    for i, c in enumerate(counts):
        for t, tf in c.items():
            idf = math.log(1 + (n - df[t] + .5) / (df[t] + .5))
            post[t] += [i, max(1, round(100 * idf * tf * (K1 + 1) / (tf + K1 * (1 - B + B * lens[i] / avg))))]
    size = sum(len(t) * 2 + 6 + len(v) * 4 for t, v in post.items())
    shards = max(1, round(size / SHARD_BYTES))

    shutil.rmtree(OUT, ignore_errors=True)
    for d in ("data/i", "data/t", "files"):
        (OUT / d).mkdir(parents=True)
    shutil.copyfile(WEB / "index.html", OUT / "index.html")
    for path, d in zip(files, docs):
        shutil.copyfile(path, OUT / "files" / d["file"])
    groups = defaultdict(dict)
    for t, v in post.items():
        groups[fnv(t) % shards][t] = v
    for k in range(shards):
        dump(OUT / f"data/i/{k}.json", groups[k])
    for k in range(0, n, PER_FILE):
        dump(OUT / f"data/t/{k // PER_FILE}.json", chunks[k:k + PER_FILE])
    # словарь для опечаток: основа -> в скольких фрагментах встречается
    dump(OUT / "data/vocab.json", {t: c for t, c in df.items() if len(t) >= 4 and not t.isdigit()})
    dump(OUT / "data/meta.json", {"v": f"{int(time.time()):x}", "built": time.strftime("%d.%m.%Y"), "docs": docs, "chunks": n,
                                  "shards": shards, "per": PER_FILE, "stop": sorted(STOP)})
    print(f"Готово: документов {len(docs)}, фрагментов {n}, слов в индексе {len(post)}, файлов индекса {shards} -> {OUT.name}/")


if __name__ == "__main__":
    build()
