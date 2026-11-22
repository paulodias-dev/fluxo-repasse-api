"""Teste HTTP de integração em empresa descartável com dois workers da API.

QA_BILLING_URL: base até /api/v1; QA_BILLING_TOKEN: JWT da empresa de QA.
Pré-condição: exatamente uma vaga disponível no plano. Cria um veículo de teste.
"""
import json
import os
import threading
import uuid
from concurrent.futures import ThreadPoolExecutor
from urllib.error import HTTPError
from urllib.request import Request, urlopen


def request(path, payload=None):
    body = json.dumps(payload).encode() if payload is not None else None
    req = Request(os.environ["QA_BILLING_URL"].rstrip("/") + path, data=body,
                  headers={"Authorization": "Bearer " + os.environ["QA_BILLING_TOKEN"],
                           "Accept": "application/json", "Content-Type": "application/json"})
    try:
        with urlopen(req, timeout=30) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        return error.code, json.load(error)


def main():
    _, before = request("/billing/overview")
    used = before["active_vehicles"]
    assert before["plan"]["vehicle_limit"] == used + 1, "O teste exige uma vaga livre"
    barrier = threading.Barrier(2)

    def create(_):
        barrier.wait(timeout=10)
        return request("/repasse-cases", {"title": "Concorrência de cota QA",
                                          "request_key": str(uuid.uuid4())})

    with ThreadPoolExecutor(2) as workers:
        results = list(workers.map(create, range(2)))
    assert sorted(status for status, _ in results) == [201, 409], results
    assert next(body for status, body in results if status == 409)["code"] == "plan_limit_reached"
    assert request("/billing/overview")[1]["active_vehicles"] == used + 1
    print("Uma criação e um bloqueio confirmados.")


if __name__ == "__main__":
    main()
