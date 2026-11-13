#!/usr/bin/env python3
"""Offline negative controls for the original-byte IPDAMS frontend assembler."""
import hashlib
import tempfile
from pathlib import Path
import unittest
import build_static as b


class StaticBuildTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / 'assets').mkdir()
        (self.root / 'private/frontend').mkdir(parents=True)
        (self.root / 'app.html').write_text('<html><script src="assets/react.production.min.js"></script><script src="assets/react-dom.production.min.js"></script><script src="assets/app.js?v=1"></script><link rel="stylesheet" href="assets/app.css"></html>', encoding='utf-8')
        self.data = {
            'react.production.min.js': '/* React v16.0.0 */\nvar React={};\n',
            'react-dom.production.min.js': '/* React v16.0.1 */\nvar ReactDOM={};\n',
        }
        for name, text in self.data.items():
            (self.root / 'assets' / name).write_text(text, encoding='utf-8')
        (self.root / 'assets/app.js').write_text('console.log("fixture");\n', encoding='utf-8')
        (self.root / 'assets/app.css').write_text('body {}\n', encoding='utf-8')
        (self.root / 'private/frontend/THIRD_PARTY_NOTICES.md').write_text('MIT fixture\n', encoding='utf-8')
        self.original = b.ROOT, b.ASSETS, b.ENTRY, b.NOTICE, b.PINNED
        b.ROOT = self.root
        b.ASSETS = self.root / 'assets'
        b.ENTRY = self.root / 'app.html'
        b.NOTICE = self.root / 'private/frontend/THIRD_PARTY_NOTICES.md'
        b.PINNED = {
            name: (
                '16.0.0' if name == 'react.production.min.js' else '16.0.1',
                hashlib.sha256(text.encode()).hexdigest(),
            )
            for name, text in self.data.items()
        }
        self.addCleanup(self.restore)

    def restore(self):
        b.ROOT, b.ASSETS, b.ENTRY, b.NOTICE, b.PINNED = self.original

    def test_identical_rebuild(self):
        one = b.build(self.root / 'out1')
        two = b.build(self.root / 'out2')
        self.assertEqual(one, two)
        self.assertEqual((self.root / 'out1/build-manifest.json').read_bytes(),
                         (self.root / 'out2/build-manifest.json').read_bytes())
        self.assertEqual((self.root / 'out1/assets/app.js').read_bytes(),
                         (self.root / 'assets/app.js').read_bytes())
        self.assertEqual(len(one['files']), 6)

    def test_runtime_tamper_rejected(self):
        (self.root / 'assets/react.production.min.js').write_text('/* modified */\n')
        with self.assertRaisesRegex(ValueError, 'checksum mismatch'):
            b.preflight()

    def test_missing_html_asset_rejected(self):
        (self.root / 'app.html').write_text('<script src="assets/not-present.js"></script>')
        with self.assertRaisesRegex(ValueError, 'Missing/unsafe asset'):
            b.preflight()

    def test_wrong_script_order_rejected(self):
        (self.root / 'app.html').write_text('<script src="assets/app.js"></script><script src="assets/react.production.min.js"></script><script src="assets/react-dom.production.min.js"></script>')
        with self.assertRaisesRegex(ValueError, 'wrong order'):
            b.preflight()

    def test_refuse_overwrite(self):
        b.build(self.root / 'out')
        with self.assertRaises(FileExistsError):
            b.build(self.root / 'out')

    def test_refuse_source_overwrite(self):
        with self.assertRaisesRegex(ValueError, 'must not replace source'):
            b.build(self.root / 'assets')


if __name__ == '__main__':
    unittest.main(verbosity=2)
