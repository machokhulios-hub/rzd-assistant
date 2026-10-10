"""Единый стиль документов: правило для загружаемых документов.

К документам из documents/_style/документы.txt при сборке сайта применяются:
  1. исправления из documents/_style/исправления.txt (орфография, пунктуация: «было -> стало»);
  2. проверка орфографии: слова, которых нет в словаре русского языка (pymorphy3) и в
     documents/_style/словарь.txt, выводятся при сборке с вариантами исправления;
     проверка пунктуации: бесспорное правится само (двойные знаки, пробел после запятой, запятая перед
     «а», «но», лишняя запятая в «не позднее чем»), а пропущенные запятые перед «который», «если», «чтобы»
     и в причастных оборотах выводятся при сборке готовой строкой «было -> стало»;
  3. типографика: кавычки «ёлочки», тире вместо дефиса между словами, знак №, неразрывные пробелы,
     лишние пробелы убираются;
  4. оформление: текст выровнен по левому и правому краю, гриф («УТВЕРЖДЕНА...») и заголовок
     документа из нескольких строк собираются в один блок.
Исходные файлы не меняются - правило действует на страницу для чтения и на поиск."""
import html
import re
from pathlib import Path

DIR = Path(__file__).parent / "documents" / "_style"
_morph = False      # pymorphy3.MorphAnalyzer или None, если пакета нет; False - ещё не загружали


def _lines(name: str) -> list[str]:
    f = DIR / name
    if not f.exists():
        return []
    return [x.strip() for x in f.read_text("utf-8").splitlines() if x.strip() and not x.lstrip().startswith("#")]


def applies(path: Path) -> bool:
    """Правило действует на документ: в списке его имя, имя без расширения, его папка или «*»."""
    names = set(_lines("документы.txt"))
    return bool(names & {"*", path.name, path.stem, path.parent.name})


def signature() -> bytes:
    """Всё, от чего зависит результат: при изменении правила документы пересобираются."""
    return Path(__file__).read_bytes() + b"".join((DIR / n).read_bytes() for n in ("документы.txt", "исправления.txt", "словарь.txt") if (DIR / n).exists())


def _corrections() -> list[tuple[re.Pattern, str, str]]:
    out = []
    for x in _lines("исправления.txt"):
        if "->" not in x:
            continue
        a, b = (s.strip() for s in x.split("->", 1))
        if a:
            out.append((re.compile(r"(?<![\w-])" + re.escape(a) + r"(?![\w-])"), a, b))
            if a[0].islower():      # то же слово в начале предложения - с большой буквы
                A, B = a[0].upper() + a[1:], b[:1].upper() + b[1:]
                out.append((re.compile(r"(?<![\w-])" + re.escape(A) + r"(?![\w-])"), A, B))
    return out


CONJ = {"и", "или", "а", "но", "либо", "да", "причем", "причём", "то", "что", "как", "также", "тоже", "не", "ни", "даже", "только", "лишь", "именно"}


class Fixer:
    """Правит текст по кускам (абзацы, ячейки, куски между тегами), помня, открыта ли кавычка."""

    def __init__(self):
        self.corr = _corrections()
        self.prev, self.depth = "\n", 0
        self.fixed = []         # (было, стало) - что исправлено
        self.raw = []           # текст до типографики - по нему проверяется пунктуация

    def punct(self, t: str) -> str:
        """Пунктуация, которую можно править без сомнений."""
        def log(rx, repl, t):
            def f(m):
                r = repl(m)
                if r != m.group():
                    self.fixed.append((m.group(), r))
                return r
            return re.sub(rx, f, t)
        t = log(r"[,;:]{2,}", lambda m: m.group()[0], t)                   # «,,» -> «,»
        t = log(r"\w+[,;][А-Яа-яЁё]+", lambda m: re.sub(r"([,;])", r"\1 ", m.group()), t)     # пробел после запятой
        # цельные выражения: «не позднее чем», «не более чем» - без запятой
        t = log(r"(?i)(?<![\w-])не (?:более|менее|позднее|позже|ранее|раньше|больше|меньше), чем(?![\w-])", lambda m: m.group().replace(",", ""), t)
        # перед противительными союзами «а», «но» - запятая
        t = log(r"(?<![\w-])([А-Яа-яЁё]+(?:-[А-Яа-яЁё]+)*) (а|но) ([а-яё]+)",
                lambda m: m.group() if m.group(1).lower() in CONJ or m.group(3) in ("и", "или")
                or m.group(1).lower().startswith(("пункт", "подпункт", "литер", "букв", "форм", "схем"))
                else f"{m.group(1)}, {m.group(2)} {m.group(3)}", t)
        return t

    def __call__(self, t: str) -> str:
        for rx, a, b in self.corr:
            t, n = rx.subn(lambda m: b, t)
            if n:
                self.fixed.append((a, b))
        t = self.punct(t)
        self.raw.append(t)
        t = re.sub(r"[ \t\u00a0]{2,}", " ", t)
        t = re.sub(r" +([,.;:!?)»])", r"\1", t)
        t = re.sub(r"([(«]) +", r"\1", t)
        t = re.sub(r"(^|(?<=\s))[-–](?=\s)", "—", t, flags=re.M)        # «далее - ПТЭ» -> «далее — ПТЭ»
        t = re.sub(r" —", "\u00a0—", t)                                 # тире не уходит в начало строки
        t = re.sub(r"(?<![\w№])N\s?(?=\d)", "№\u00a0", t)               # «N 1215/р» -> «№ 1215/р»
        t = re.sub(r"(\d) (г\.|гг\.|ч\.|мин\.|км|м|мм|кг|т|%)(?!\w)", "\\1\u00a0\\2", t)
        t = re.sub(r"(?<!\w)(ОАО|ПАО|АО|ООО) (?=\S)", "\\1\u00a0", t)               # «ОАО «РЖД»» не разрывается
        out = []
        for ch in t:
            if ch == "«":
                self.depth += 1
            elif ch == "»":
                self.depth = max(0, self.depth - 1)
            elif ch in "\"“”„":
                if self.prev.isspace() or self.prev in "([«„-—/":
                    ch = "«" if self.depth == 0 else "„"
                    self.depth += 1
                else:
                    self.depth = max(0, self.depth - 1)
                    ch = "»" if self.depth == 0 else "“"
            out.append(ch)
            self.prev = ch
        return "".join(out)


def _known(word: str, own: set) -> bool:
    w = word.lower().replace("ё", "е")
    return w in own or _morph.word_is_known(w)


def _suggest(word: str, own: set) -> list[str]:
    """Слова словаря, отличающиеся одной буквой (пропущена, лишняя, заменена, переставлена)."""
    w, abc = word.lower(), "абвгдежзийклмнопрстуфхцчшщъыьэюя"
    c = {w[:i] + w[i + 1:] for i in range(len(w))}
    c |= {w[:i] + w[i + 1] + w[i] + w[i + 2:] for i in range(len(w) - 1)}
    c |= {w[:i] + a + w[i + 1:] for i in range(len(w)) for a in abc}
    c |= {w[:i] + a + w[i:] for i in range(len(w) + 1) for a in abc}
    return sorted(x for x in c if len(x) > 2 and _known(x, own))[:3]


def _analyzer():
    global _morph
    if _morph is False:
        try:
            import pymorphy3
            _morph = pymorphy3.MorphAnalyzer()
        except ImportError:
            _morph = None
            print("  проверка орфографии и пунктуации пропущена: нужен pymorphy3 (pip install -r requirements.txt)")
    return _morph


def spelling(text: str) -> list[str]:
    """Слова, которых нет в словаре: «слово (может быть: ...)». Без pymorphy3 проверка пропускается."""
    if _analyzer() is None:
        return []
    own = {x.lower().replace("ё", "е") for x in _lines("словарь.txt")}
    out, seen = [], set()
    for w in re.findall(r"[А-Яа-яЁё]+(?:-[А-Яа-яЁё]+)*", text):
        if w in seen:
            continue
        seen.add(w)
        if w.lower() in own:
            continue
        for part in w.split("-"):
            if len(part) < 3 or part.isupper() or _known(part, own):    # аббревиатуры (ПТЭ, ТЧД) не проверяем
                continue
            s = _suggest(part, own)
            out.append(w + (f" (может быть: {', '.join(s)})" if s else ""))
            break
    return out


TOK = re.compile(r"[А-Яа-яЁё]+(?:-[А-Яа-яЁё]+)*|\d+|\S")


def _agree(prt: str, word: str) -> bool:
    """Причастие согласовано со словом: тот же падеж и число (и род в единственном)."""
    for a in (p for p in _morph.parse(prt.lower()) if "PRTF" in p.tag):
        for b in _morph.parse(word.lower())[:4]:
            if b.tag.POS in ("NOUN", "NPRO") and b.tag.case == a.tag.case and b.tag.number == a.tag.number \
                    and (a.tag.number == "plur" or b.tag.gender in (None, a.tag.gender)):
                return True
    return False


def punctuation(text: str) -> list[tuple[str, str]]:
    """Где, похоже, не хватает запятой: [(было, стало)] - готовые строки для исправления.txt.
    Проверяется: придаточное с «который», «если», «чтобы», «поскольку»; причастный оборот после
    слова, к которому он относится («локомотивов следующих в...» -> «локомотивов, следующих в...»)."""
    if _analyzer() is None:
        return []
    toks = [(x.group(), x.start()) for x in TOK.finditer(text)]
    T = [t for t, _ in toks]
    word = lambda i: 0 <= i < len(T) and T[i][0].isalpha()
    pos = lambda i: _morph.parse(T[i].lower())[0].tag.POS if word(i) else None
    out = []

    def comma(k, i):    # запятая перед словом k; во фрагменте - по слову до и после
        a = max(0, k - 2)
        while a < k and not word(a):
            a += 1
        e = toks[min(len(T) - 1, i + 1)]
        before = text[toks[a][1]:e[1] + len(e[0])]
        cut = toks[k][1] - toks[a][1]
        out.append((before, before[:cut].rstrip() + ", " + before[cut:]))

    for i, t in enumerate(T):
        if not word(i) or not word(i - 1):
            continue
        lo = t.lower()
        if lo.startswith("котор"):
            k = i
            if _morph.parse(lo)[0].tag.case == "gent":     # «работа которых», «каждый из которых»
                while k > i - 5 and pos(k - 1) in ("NOUN", "NPRO", "ADJF", "PRTF", "NUMR", "PREP", "INFN", "ADVB"):
                    k -= 1
            else:                                          # «в котором», «руководить которыми»
                while pos(k - 1) == "PREP":
                    k -= 1
                if pos(k - 1) == "INFN":
                    k -= 1
            if word(k - 1) and T[k - 1].lower() not in CONJ:
                comma(k, i)
        elif t in ("если", "чтобы", "поскольку") and lo not in CONJ and T[i - 1].lower() not in CONJ | {"того", "случае", "так"}:
            comma(i, i)
        elif word(i + 1) and len(T[i - 1]) > 2 and any("PRTF" in p.tag for p in _morph.parse(lo)) and pos(i - 1) == "NOUN" \
                and T[i - 1].lower() not in ("числа", "числе") and _agree(t, T[i - 1]):     # «из числа предусмотренных»
            # причастие перед своим словом («обслуживаемых участков») - не оборот
            if not any(word(j) and _agree(t, T[j]) for j in range(i + 1, min(len(T), i + 7)) if all(word(x) for x in range(i + 1, j + 1))):
                comma(i, i + 1)
    return out


def page_body(body: str, name: str) -> str:
    """HTML страницы для чтения -> HTML единого стиля; найденное при проверке печатается при сборке."""
    fix = Fixer()
    pieces = re.split(r"(<[^>]+>)", body)
    for i, p in enumerate(pieces):
        if p and not p.startswith("<"):
            pieces[i] = html.escape(fix(html.unescape(p)), quote=False)
    lines = "".join(pieces).split("\n")
    out = []
    for x in lines:     # гриф справа и заголовок документа в несколько строк - одним блоком
        prev = out[-1] if out else ""
        if x.startswith('<p class="r">') and prev.startswith('<p class="r">'):
            out[-1] = prev[:-4] + "<br>" + x[len('<p class="r">'):]
        elif x.startswith("<h2>") and prev.startswith("<h2>") and not any(l.startswith(("<p>", "<h3>")) for l in out) \
                and not re.match(r"<h2>(?:[IVXLC]+\.|\d+\.|Глава|ГЛАВА|Раздел|РАЗДЕЛ)\s", x):
            out[-1] = prev[:-5] + "<br>" + x[4:]
        else:
            out.append(x)
    body = "\n".join(out)
    plain = html.unescape(re.sub(r"<[^>]+>", " ", body))
    bad = spelling(plain)
    commas = list(dict.fromkeys(x for t in fix.raw for x in punctuation(t)))
    if fix.fixed or bad or commas:
        print(f"  {name} - единый стиль:")
    for a, b in dict.fromkeys(fix.fixed):
        print(f"    исправлено: {a} -> {b}")
    for w in bad:      # верное слово - в documents/_style/словарь.txt, ошибку - в исправления.txt
        print(f"    проверьте орфографию: {w}")
    for a, b in commas:     # если запятая нужна - строку «было -> стало» переносят в исправления.txt
        print(f"    проверьте пунктуацию: {a} -> {b}")
    return body
