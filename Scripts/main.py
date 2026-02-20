from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import subprocess
from pathlib import Path

APP_DIR = Path(__file__).parent.resolve()
DEPLOY_SCRIPT = APP_DIR / "deploy.py"

app = FastAPI()

class DeployRequest(BaseModel):
    ip: str
    email: str
    name: str

@app.get("/health")
def health():
    return {"ok": True}

@app.post("/deploy")
def deploy(req: DeployRequest):
    if not DEPLOY_SCRIPT.exists():
        raise HTTPException(status_code=500, detail="deploy.py introuvable")

    cmd = ["python3", str(DEPLOY_SCRIPT), "--ip", req.ip, "--email", req.email, "--name", req.name]

    try:
        completed = subprocess.run(cmd, cwd=str(APP_DIR), capture_output=True, text=True, timeout=3600)
    except subprocess.TimeoutExpired:
        raise HTTPException(status_code=504, detail="Timeout")

    if completed.returncode != 0:
        raise HTTPException(status_code=500, detail=completed.stderr)

    return {"status": "success", "stdout": completed.stdout[-1000:]}