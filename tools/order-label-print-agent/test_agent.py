import base64
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from agent import AgentError, StateStore, handle_job, validated_pdf


def job(queue="Brother_QL_820NWB"):
    return {
        "job_id": 10,
        "claim_token": "claim-1",
        "target": "order_label_printer",
        "doc_type": "order_label_pdf",
        "payload_base64": base64.b64encode(b"%PDF-1.4 test").decode("ascii"),
        "metadata": {
            "printer_queue": queue,
            "width_tenths_mm": 580,
            "height_tenths_mm": 620,
            "resolution_dpi": 300,
        },
    }


class AgentTest(unittest.TestCase):
    def test_rejects_unknown_queue_and_document_type(self):
        with self.assertRaises(AgentError) as queue_error:
            validated_pdf(job("unknown"), {"Brother_QL_820NWB": {}})
        self.assertEqual(queue_error.exception.code, "QUEUE_NOT_ALLOWLISTED")

        invalid = job()
        invalid["doc_type"] = "receipt"
        with self.assertRaises(AgentError) as type_error:
            validated_pdf(invalid, {"Brother_QL_820NWB": {}})
        self.assertEqual(type_error.exception.code, "UNSUPPORTED_DOCUMENT")

    @patch("agent.submit_pdf")
    def test_persists_before_printing_and_deduplicates_replay(self, submit_pdf):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.sqlite3")
            client = Mock()
            queues = {"Brother_QL_820NWB": {}}
            handle_job(client, store, queues, job())
            handle_job(client, store, queues, job())

            submit_pdf.assert_called_once()
            self.assertEqual(store.state(10), "acked")
            self.assertEqual(client.ack.call_count, 2)

    @patch("agent.submit_pdf")
    def test_ambiguous_previous_submission_is_not_printed_again(self, submit_pdf):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.sqlite3")
            store.mark(10, "claim-old", "submitting")
            client = Mock()
            handle_job(client, store, {"Brother_QL_820NWB": {}}, job())

            submit_pdf.assert_not_called()
            client.ack.assert_called_once_with(
                10,
                "claim-1",
                "failed",
                "AMBIGUOUS_LOCAL_STATE",
                "Manual review required; the previous OS submission result is unknown",
            )


if __name__ == "__main__":
    unittest.main()
